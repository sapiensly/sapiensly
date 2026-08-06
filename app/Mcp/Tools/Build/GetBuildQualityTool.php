<?php

namespace App\Mcp\Tools\Build;

use App\Mcp\Tools\SapiensTool;
use App\Models\User;
use App\Services\Builder\BuildFindingLedger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('What has been going WRONG in app builds — the counterpart to get_build_cost, which only says what they cost. Reads the build-failure ledger: every time a build was told it got something wrong, kept instead of discarded after the turn. Three signals: `patch_rejected` (propose_change refused the ops — the model believed something untrue about the manifest), `design_smell` (the patch applied but the validator warned about how it was built), and `critic` (the closing review\'s verdict, coded `missing` for something asked-for and absent, `unrequested` for invented subject matter). Omit app_slug for the whole organization — that is the view that answers "what fails most often?" — or pass it to scope to one app. `top_codes` ranks the recurring patterns, and each one is a candidate for a deterministic rule rather than more prompt text. `by_model` divides findings by the builds that produced them (`per_build`), which is how "would a stronger builder model do better?" becomes evidence instead of a hunch — read it only once each model has several builds behind it. `recent` quotes what the failures actually said. Nothing here is fed back into any prompt; it is telemetry for a human deciding which rail to write next.')]
class GetBuildQualityTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['nullable', 'string'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'recent_limit' => ['sometimes', 'integer', 'min:0', 'max:100'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $appId = null;
        $slug = $validated['app_slug'] ?? null;
        if ($slug !== null && trim($slug) !== '') {
            try {
                $appId = $this->resolveApp($slug, $user)->id;
            } catch (ModelNotFoundException) {
                return Response::error("No app named '{$slug}' is visible to you.");
            }
        }

        // RLS scopes the ledger to the caller's own builds; an app_slug, when
        // given, is visibility-checked above.
        $report = app(BuildFindingLedger::class)->report(
            $appId,
            $validated['days'] ?? 30,
            $validated['recent_limit'] ?? 20,
        );

        $report['scope'] = $appId === null ? 'organization' : $slug;

        if (($report['totals']['findings'] ?? 0) === 0) {
            $report['note'] = 'No findings recorded in this window. Either the builds came out clean, or they predate the ledger — recording began when the build_findings table shipped.';
        }

        return Response::json($report);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app_slug' => $schema->string()
                ->description('Optional: scope to one app. Omit for every build in the organization — the view that surfaces recurring patterns.'),
            'days' => $schema->integer()
                ->description('Look-back window in days (default 30, max 365).'),
            'recent_limit' => $schema->integer()
                ->description('How many individual findings to quote verbatim (default 20, max 100). Set 0 for counts only.'),
        ];
    }
}
