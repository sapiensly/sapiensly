<?php

namespace App\Http\Controllers\Settings;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\OrganizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $user->organization_id) {
            return to_route('organization.create');
        }

        $organization = $user->organization;

        $members = OrganizationMembership::where('organization_id', $organization->id)
            ->where('status', MembershipStatus::Active)
            ->with('user:id,name,email,avatar')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'user' => $m->user,
                'role' => $m->role->value,
                'status' => $m->status->value,
            ]);

        return Inertia::render('settings/Organization', [
            'organization' => $organization,
            'members' => $members,
            'isAdmin' => $user->hasRole('owner') || $user->hasRole('sysadmin'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('settings/OrganizationCreate');
    }

    public function store(Request $request, OrganizationService $organizationService): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();

        $organization = Organization::create([
            'name' => $request->name,
            'slug' => Str::slug($request->name),
        ]);

        OrganizationMembership::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => MembershipRole::Owner,
            'status' => MembershipStatus::Active,
        ]);

        $organizationService->switchAccount($user, $organization->id);

        return to_route('organization.show');
    }

    public function invite(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $user = $request->user();

        if (! $user->organization_id) {
            return back()->withErrors(['email' => __('You must be in an organization to send invitations.')]);
        }

        $organization = $user->organization;

        if (! $user->hasPermissionTo('organization.invite-members')) {
            return back()->withErrors(['email' => __('You do not have permission to send invitations.')]);
        }

        // Check if user is already a member
        $existingMember = OrganizationMembership::whereHas('user', fn ($q) => $q->where('email', $request->email))
            ->where('organization_id', $organization->id)
            ->where('status', MembershipStatus::Active)
            ->exists();

        if ($existingMember) {
            return back()->withErrors(['email' => __('This user is already a member of the organization.')]);
        }

        // TODO: Implement local invitation system (email notification with token)
        // For now, if the user exists, add them directly
        $invitedUser = User::where('email', $request->email)->first();

        if ($invitedUser) {
            OrganizationMembership::create([
                'organization_id' => $organization->id,
                'user_id' => $invitedUser->id,
                'role' => MembershipRole::Member,
                'status' => MembershipStatus::Active,
            ]);

            return back()->with('success', __('Member added successfully.'));
        }

        return back()->withErrors(['email' => __('User not found. They must register first.')]);
    }
}
