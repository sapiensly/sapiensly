<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\Ai\AiUsageReport;
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

        if ($user->organization_id !== null) {
            $organization = Organization::findOrFail($user->organization_id);
            Gate::authorize('viewAiSpend', $organization);
            $scope = ['type' => 'organization', 'name' => $organization->name];
        } else {
            $scope = ['type' => 'personal', 'name' => $user->name];
        }

        return Inertia::render('system/AiSpend/Dashboard', [
            'scope' => $scope,
            'days' => $days,
            'report' => $this->report->forCurrentOrg($days),
        ]);
    }
}
