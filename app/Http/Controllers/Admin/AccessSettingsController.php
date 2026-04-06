<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccessSettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/AccessSettings', [
            'settings' => [
                'registration_enabled' => AppSetting::isRegistrationEnabled(),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'registration_enabled' => ['required', 'boolean'],
        ]);

        AppSetting::setValue('registration_enabled', $validated['registration_enabled'] ? 'true' : 'false');

        return back()->with('success', __('Access settings updated.'));
    }
}
