<?php

namespace App\Http\Controllers;

use App\Services\OrganizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AccountSwitchController extends Controller
{
    public function __invoke(Request $request, OrganizationService $organizationService): RedirectResponse
    {
        $request->validate([
            'organization_id' => ['nullable', 'string'],
        ]);

        try {
            $organizationService->switchAccount(
                $request->user(),
                $request->organization_id
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['organization_id' => $e->getMessage()]);
        }

        // After a successful switch, land on the dashboard. Staying on the
        // current page after switching tenant would leave the user inside a
        // resource (e.g. `/system/integrations/{id}`) that the new tenant
        // can't see, producing a 403 or stale context. Dashboard is the
        // neutral entry point that re-hydrates with the new org's props.
        return to_route('dashboard');
    }
}
