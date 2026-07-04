<?php

namespace App\Ai\Tools\Builder;

use App\Models\App;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\RecordQueryService;
use App\Support\Branding\ColorPalette;
use App\Support\Branding\OrganizationBrand;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Fused dashboard discovery: blueprint + data profile + brand in ONE call.
 * Individually these are three round-trips (list_dashboard_blueprints →
 * profile_object → brand), and with a slow model each round-trip eats the
 * hard per-turn time budget — this collapses the whole discovery phase so the
 * turn reaches add_dashboard_page (the banked commit) fast.
 */
class PrepareDashboardTool implements Tool
{
    public function __construct(
        private App $appModel,
        private AppManifestService $manifestService,
        private RecordQueryService $records,
        private ?ProposeChangeTool $proposeTool = null,
    ) {}

    public function name(): string
    {
        return 'prepare_dashboard';
    }

    public function description(): string
    {
        return <<<'DESC'
ONE-CALL dashboard discovery — use this instead of separate
list_dashboard_blueprints + profile_object + brand calls. Pass `object_id` and
optionally `sector` (support | sales_crm | ecommerce_retail | saas_subscriptions
| general; default general). Returns {blueprint (the sector's expert KPI set,
charts and insight conclusions), profile (each field's analytic role + live
stats + viz hints), brand (accent + palette ramp + chart colors)}. Map the
blueprint's KPIs/charts to the profiled fields, then build with ONE
add_dashboard_page call.
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'object_id' => $schema->string()
                ->description('The object_definition id (obj_…) the dashboard will read.')
                ->required(),
            'sector' => $schema->string()
                ->description('Closest blueprint sector: support | sales_crm | ecommerce_retail | saas_subscriptions | general (default).'),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->all();
        $objectId = trim((string) ($args['object_id'] ?? ''));
        if ($objectId === '') {
            return json_encode([
                'ok' => false,
                'errors' => [['path' => '/object_id', 'message' => '`object_id` is required.', 'code' => 'bad_input']],
            ], JSON_THROW_ON_ERROR);
        }
        $sector = trim((string) ($args['sector'] ?? '')) ?: 'general';

        $blueprint = json_decode(
            (new ListDashboardBlueprintsTool)->handle(new Request(['sector' => $sector])),
            true,
        );

        $profile = json_decode(
            (new ProfileObjectTool($this->appModel, $this->manifestService, $this->records, $this->proposeTool))
                ->handle(new Request(['object_id' => $objectId])),
            true,
        );

        $brandbook = $this->appModel->organization?->brandbook() ?? OrganizationBrand::fromArray(null);
        $accent = $brandbook->effectiveAccent();

        return json_encode([
            'ok' => ! isset($profile['error']),
            'blueprint' => $blueprint,
            'profile' => $profile,
            'brand' => ['accent' => $accent] + ColorPalette::fromAccent($accent),
            'next' => 'Map the blueprint KPIs/charts to the profiled fields and build with ONE add_dashboard_page call.',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
