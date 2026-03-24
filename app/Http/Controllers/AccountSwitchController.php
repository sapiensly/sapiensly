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

        return back();
    }
}
