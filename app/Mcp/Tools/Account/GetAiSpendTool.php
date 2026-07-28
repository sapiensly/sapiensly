<?php

namespace App\Mcp\Tools\Account;

use App\Mcp\Tools\SapiensTool;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiUsageReport;
use App\Support\Ai\SpendPeriod;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description("The organization's AI spend over a window — today, this week, this month, or the last 7/30/90 days: total cost, calls and tokens, the own-key vs platform-key split, a per-model breakdown, a per-service breakdown (Chat, Apps, … each split BOTH by model and by the named artifact the spend was made on: an app, a chatbot, a chat, a deck, a knowledge base), a cost series (hourly for today, otherwise daily), and the configured budgets/caps. Spend recorded before artifact tagging shipped appears as one unattributed line rather than being dropped. Owner-only.")]
class GetAiSpendTool extends SapiensTool
{
    // No ability gate; owner-gated below to match the web AI Spend dashboard.

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'period' => ['sometimes', 'string', 'in:'.implode(',', SpendPeriod::keys())],
            'days' => ['sometimes', 'integer', 'in:7,30,90'],
        ]);

        $period = isset($validated['period'])
            ? SpendPeriod::fromKey($validated['period'])
            : SpendPeriod::rolling($validated['days'] ?? 30);

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
        $report = app(AiUsageReport::class)->forCurrentOrg($period);

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
            'period' => $schema->string()->enum(SpendPeriod::keys())
                ->description("Window: 'today', 'week' (this week), 'month' (this month), or '7d'/'30d'/'90d'. Default '30d'."),
            'days' => $schema->integer()->enum([7, 30, 90])->description('Legacy rolling window in days; prefer `period`.'),
        ];
    }
}
