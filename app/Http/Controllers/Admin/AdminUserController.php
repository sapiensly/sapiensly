<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class AdminUserController extends Controller
{
    public function index(Request $request): Response
    {
        $users = User::query()
            ->with('organization:id,name')
            ->withCount('memberships')
            ->latest()
            ->paginate(20);

        return Inertia::render('admin/Users', [
            'users' => $users,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/UserForm', [
            'mode' => 'create',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::defaults()],
            'email_verified' => ['boolean'],
        ]);

        User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'email_verified_at' => ($validated['email_verified'] ?? false) ? now() : null,
        ]);

        return to_route('admin.users')->with('success', __('User created.'));
    }

    public function edit(User $user): Response
    {
        return Inertia::render('admin/UserForm', [
            'mode' => 'edit',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified' => $user->email_verified_at !== null,
                'blocked' => $user->isBlocked(),
            ],
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'password' => ['nullable', Password::defaults()],
            'email_verified' => ['boolean'],
        ]);

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'email_verified_at' => ($validated['email_verified'] ?? false) ? ($user->email_verified_at ?? now()) : null,
            ...($validated['password'] ? ['password' => $validated['password']] : []),
        ]);

        return to_route('admin.users')->with('success', __('User updated.'));
    }

    public function block(Request $request, User $user): RedirectResponse
    {
        $request->validate([
            'email_confirmation' => ['required', 'string', 'in:'.$user->email],
        ]);

        if ($request->user()->id === $user->id) {
            return back()->withErrors(['email_confirmation' => __('You cannot block yourself.')]);
        }

        $user->update(['blocked_at' => $user->isBlocked() ? null : now()]);

        $action = $user->isBlocked() ? __('blocked') : __('unblocked');

        return back()->with('success', __('User :action.', ['action' => $action]));
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $request->validate([
            'email_confirmation' => ['required', 'string', 'in:'.$user->email],
        ]);

        if ($request->user()->id === $user->id) {
            return back()->withErrors(['email_confirmation' => __('You cannot delete yourself.')]);
        }

        $user->memberships()->delete();
        $user->delete();

        return to_route('admin.users')->with('success', __('User deleted.'));
    }
}
