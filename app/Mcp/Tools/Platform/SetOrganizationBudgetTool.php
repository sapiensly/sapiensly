<?php

namespace App\Mcp\Tools\Platform;

use App\Mcp\Tools\SysadminTool;
use App\Models\Organization;
use App\Models\OrganizationAiBudget;
use App\Models\User;
use App\Services\Ai\AiUsageReport;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description("Set an organization's AI spend limits. platform_system_cap is the ceiling YOU impose on what the organization may spend on platform-provided keys — the org cannot raise it. system_monthly_budget / own_monthly_budget are the org's own targets, and enforcement_enabled decides whether a limit blocks calls or only warns. The effective system limit is the LOWER of the org budget and the platform cap, so a cap below their budget takes over. Pass null to clear a limit (uncapped). Only the fields you pass change. Audited.")]
class SetOrganizationBudgetTool extends SysadminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'organization' => ['required', 'string', 'max:120'],
            'platform_system_cap' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'system_monthly_budget' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'own_monthly_budget' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'alert_threshold_pct' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'reset_day' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:28'],
            'enforcement_enabled' => ['sometimes', 'boolean'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        $organization = Organization::query()
            ->where('id', $validated['organization'])
            ->orWhere('slug', $validated['organization'])
            ->first();

        if ($organization === null) {
            return Response::error("No organization matches '{$validated['organization']}' (pass its id or slug).");
        }

        $fields = [
            'platform_system_cap',
            'system_monthly_budget',
            'own_monthly_budget',
            'alert_threshold_pct',
            'reset_day',
            'enforcement_enabled',
        ];

        $changes = [];
        foreach ($fields as $field) {
            if ($request->has($field)) {
                $changes[$field] = $validated[$field] ?? null;
            }
        }

        if ($changes === []) {
            return Response::error('Nothing to change — pass at least one limit.');
        }

        $budget = OrganizationAiBudget::updateOrCreate(
            ['organization_id' => $organization->id],
            $changes,
        )->refresh();

        $this->audit(
            actor: $actor,
            summary: "Updated AI budget for '{$organization->name}': ".implode(', ', array_map(
                static fn (string $key, $value) => $key.'='.($value === null ? 'unset' : $value),
                array_keys($changes),
                $changes,
            )),
            meta: $changes,
            organizationId: $organization->id,
            targetType: 'organization_ai_budget',
            targetId: $organization->id,
            targetLabel: $organization->name,
        );

        $spend = app(AiUsageReport::class)->forOrganization($organization->id, 30);
        $effectiveSystem = $budget->effectiveLimit('system');
        $systemSpend = (float) ($spend['by_source']['system'] ?? 0);

        return Response::json([
            'organization' => ['id' => $organization->id, 'name' => $organization->name],
            'budget' => [
                'platform_system_cap' => $budget->platform_system_cap,
                'system_monthly_budget' => $budget->system_monthly_budget,
                'own_monthly_budget' => $budget->own_monthly_budget,
                'alert_threshold_pct' => $budget->alert_threshold_pct,
                'reset_day' => $budget->reset_day,
                'enforcement_enabled' => (bool) $budget->enforcement_enabled,
                'effective_system_limit' => $effectiveSystem,
            ],
            'context' => [
                'system_spend_last_30_days' => round($systemSpend, 4),
                'already_over_limit' => $effectiveSystem !== null && $systemSpend > $effectiveSystem,
            ],
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization id (org_…) or slug.')->required(),
            'platform_system_cap' => $schema->number()->description('Hard platform ceiling on system (platform-key) spend per month. null clears it.'),
            'system_monthly_budget' => $schema->number()->description("The org's own monthly budget for system spend. null clears it."),
            'own_monthly_budget' => $schema->number()->description("The org's monthly budget for their BYOK spend. null clears it."),
            'alert_threshold_pct' => $schema->integer()->description('Percentage of the limit that triggers an alert (1-100).'),
            'reset_day' => $schema->integer()->description('Day of month the budget window resets (1-28).'),
            'enforcement_enabled' => $schema->boolean()->description('true blocks calls past the limit; false only warns.'),
        ];
    }
}
