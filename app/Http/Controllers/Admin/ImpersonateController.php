<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImpersonateController extends Controller
{
    public function start(Request $request, User $user): RedirectResponse
    {
        // Prevent impersonating yourself
        if ($request->user()->id === $user->id) {
            return back()->withErrors(['impersonate' => __('You cannot impersonate yourself.')]);
        }

        // Store original user ID in session
        $request->session()->put('impersonating_from', $request->user()->id);

        Auth::login($user);
        setPermissionsTeamId($user->organization_id);

        return to_route('dashboard');
    }

    public function stop(Request $request): RedirectResponse
    {
        $originalUserId = $request->session()->pull('impersonating_from');

        if (! $originalUserId) {
            return to_route('dashboard');
        }

        $originalUser = User::findOrFail($originalUserId);

        Auth::login($originalUser);
        setPermissionsTeamId($originalUser->organization_id);

        return to_route('admin.users');
    }
}
