<?php

namespace App\Mcp\Tools\Account;

use App\Mcp\Tools\SapiensTool;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiUsageReport;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description("The organization's AI spend over the last 7/30/90 days: total cost, calls and tokens, the own-key vs platform-key split, a per-model breakdown, a daily cost series, and the configured budgets/caps. Owner-only.")]
class GetAiSpendTool extends SapiensTool
{
    // No ability gate; owner-gated below to match the web AI Spend dashboard.

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'days' => ['sometimes', 'integer', 'in:7,30,90'],
        ]);
        $days = $validated['days'] ?? 30;

        /** @var User $user */
        $user = $request->user();

        if ($user->organization_id === null) {
            return Response::error('This connection is not bound to an organization.');
        }

        $org = Organization::find($user->organization_id);
        if ($org === null || ! $user->can('viewAiSpend', $org)) {
            return Response::error('AI spend is available to organization owners only.');
        }

        // RLS scopes the report to the bound organization (the request set the
        // tenant context), mirroring the org-facing AI Spend dashboard.
        $report = app(AiUsageReport::class)->forCurrentOrg($days);

        $budget = $org->aiBudget;
        $report['budget'] = $budget === null ? null : [
            'system_monthly_budget' => $budget->system_monthly_budget,
            'own_monthly_budget' => $budget->own_monthly_budget,
            'platform_system_cap' => $budget->platform_system_cap,
            'alert_threshold_pct' => $budget->alert_threshold_pct,
            'enforcement_enabled' => $budget->enforcement_enabled,
        ];

        return Response::json($report);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'days' => $schema->integer()->enum([7, 30, 90])->description('Window in days (7, 30 or 90; default 30).'),
        ];
    }
}
