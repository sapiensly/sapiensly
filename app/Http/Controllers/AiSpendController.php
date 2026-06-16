<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\OrganizationAiBudget;
use App\Services\Ai\AiUsageReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Org-facing AI spend dashboard: an organization owner sees their org's AI usage
 * and cost (own vs system, by model, over time). Personal users see their own.
 * Data is RLS-scoped to the caller via the tenant connection.
 */
class AiSpendController extends Controller
{
    public function __construct(private readonly AiUsageReport $report) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $days = (int) ($request->integer('days') ?: 30);
        if (! in_array($days, [7, 30, 90], true)) {
            $days = 30;
        }

        $budget = null;
        if ($user->organization_id !== null) {
            $organization = Organization::findOrFail($user->organization_id);
            Gate::authorize('viewAiSpend', $organization);
            $scope = ['type' => 'organization', 'name' => $organization->name];
            $budget = $organization->aiBudget;
        } else {
            $scope = ['type' => 'personal', 'name' => $user->name];
        }

        return Inertia::render('system/AiSpend/Dashboard', [
            'scope' => $scope,
            'days' => $days,
            'report' => $this->report->forCurrentOrg($days),
            'budget' => $budget ? [
                'system_monthly_budget' => $budget->system_monthly_budget,
                'own_monthly_budget' => $budget->own_monthly_budget,
                'platform_system_cap' => $budget->platform_system_cap,
                'alert_threshold_pct' => $budget->alert_threshold_pct,
                'enforcement_enabled' => $budget->enforcement_enabled,
            ] : null,
        ]);
    }

    /**
     * Owner-set budget for their org. The platform's system cap (if any) still
     * applies on top at enforcement time — the owner can't raise spend past it.
     */
    public function updateBudget(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user->organization_id === null, 403);

        $organization = Organization::findOrFail($user->organization_id);
        Gate::authorize('viewAiSpend', $organization);

        $data = $request->validate([
            'system_monthly_budget' => ['nullable', 'numeric', 'min:0'],
            'own_monthly_budget' => ['nullable', 'numeric', 'min:0'],
            'alert_threshold_pct' => ['required', 'integer', 'min:1', 'max:100'],
            'enforcement_enabled' => ['required', 'boolean'],
        ]);

        OrganizationAiBudget::updateOrCreate(
            ['organization_id' => $organization->id],
            $data, // platform_system_cap is sysadmin-only, never set here
        );

        return back()->with('success', 'AI budget updated.');
    }
}
