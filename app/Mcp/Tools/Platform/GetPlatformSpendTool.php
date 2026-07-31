<?php

namespace App\Mcp\Tools\Platform;

use App\Mcp\Tools\SysadminTool;
use App\Models\Organization;
use App\Models\OrganizationAiBudget;
use App\Services\Ai\AiUsageReport;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('AI spend across the WHOLE platform for a window (7, 30 or 90 days): totals, the split between "system" (what the platform pays, on its own keys) and "own" (what organizations pay on their own keys), the top spending organizations against their caps, per-model and per-service breakdowns, and a daily series. Pass an organization to drill into just that tenant instead. Models with usage but no recorded price are flagged as unpriced — their spend meters as $0 and slips past every budget. Read-only.')]
class GetPlatformSpendTool extends SysadminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'days' => ['sometimes', 'integer', 'in:7,30,90'],
            'organization' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $days = (int) ($validated['days'] ?? 30);
        $report = app(AiUsageReport::class);

        if (! empty($validated['organization'])) {
            $organization = Organization::query()
                ->where('id', $validated['organization'])
                ->orWhere('slug', $validated['organization'])
                ->first();

            if ($organization === null) {
                return Response::error("No organization matches '{$validated['organization']}' (pass its id or slug).");
            }

            $scoped = $report->forOrganization($organization->id, $days);
            $budget = OrganizationAiBudget::query()->where('organization_id', $organization->id)->first();

            return Response::json([
                'scope' => 'organization',
                'organization' => ['id' => $organization->id, 'name' => $organization->name, 'slug' => $organization->slug],
                'range_days' => $days,
                'totals' => $scoped['totals'] ?? null,
                'by_source' => $scoped['by_source'] ?? null,
                'by_service' => $scoped['by_service'] ?? [],
                'by_model' => $scoped['by_model'] ?? [],
                'series' => $scoped['series'] ?? null,
                'limits' => $budget === null ? null : [
                    'platform_system_cap' => $budget->platform_system_cap,
                    'effective_system_limit' => $budget->effectiveLimit('system'),
                    'enforcement_enabled' => (bool) $budget->enforcement_enabled,
                ],
            ]);
        }

        $platform = $report->platformWide($days);

        $caps = OrganizationAiBudget::query()
            ->whereNotNull('platform_system_cap')
            ->pluck('platform_system_cap', 'organization_id');

        $byOrg = array_map(static function (array $row) use ($caps) {
            $cap = $row['organization_id'] === null ? null : ($caps[$row['organization_id']] ?? null);

            return [
                ...$row,
                'platform_system_cap' => $cap,
                'over_cap' => $cap !== null && (float) $row['system_cost'] > (float) $cap,
            ];
        }, $platform['by_org'] ?? []);

        $unpriced = array_values(array_filter(
            $platform['by_model'] ?? [],
            static fn (array $model) => ($model['unpriced'] ?? false) === true,
        ));

        return Response::json([
            'scope' => 'platform',
            'range_days' => $days,
            'totals' => $platform['totals'] ?? null,
            'by_source' => $platform['by_source'] ?? null,
            'by_organization' => $byOrg,
            'by_service' => $platform['by_service'] ?? [],
            'by_model' => $platform['by_model'] ?? [],
            'series' => $platform['series'] ?? null,
            'unpriced_models' => $unpriced,
            'note' => '"system" is what the platform pays on its own keys; "own" is what organizations pay on theirs. Only system spend hits your caps.',
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'days' => $schema->integer()->description('Window: 7, 30 or 90. Default 30.'),
            'organization' => $schema->string()->description('Drill into one organization (id or slug) instead of the whole platform.'),
        ];
    }
}
