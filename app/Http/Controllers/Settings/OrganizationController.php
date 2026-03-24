<?php

namespace App\Http\Controllers\Settings;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Services\OrganizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use WorkOS\Organizations;
use WorkOS\UserManagement;

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
            'isAdmin' => OrganizationMembership::where('user_id', $user->id)
                ->where('organization_id', $organization->id)
                ->where('role', MembershipRole::Admin)
                ->exists(),
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

        try {
            $workosOrganizations = new Organizations;
            $workosOrg = $workosOrganizations->createOrganization($request->name);

            $organization = Organization::create([
                'workos_organization_id' => $workosOrg->id,
                'name' => $workosOrg->name,
                'slug' => $workosOrg->slug ?? null,
            ]);

            // Create admin membership via WorkOS
            if ($user->workos_id) {
                $workosUserMgmt = new UserManagement;
                $workosMembership = $workosUserMgmt->createOrganizationMembership(
                    organizationId: $workosOrg->id,
                    userId: $user->workos_id,
                    roleSlug: 'admin'
                );

                OrganizationMembership::create([
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                    'workos_membership_id' => $workosMembership->id,
                    'role' => MembershipRole::Admin,
                    'status' => MembershipStatus::Active,
                ]);
            } else {
                OrganizationMembership::create([
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                    'workos_membership_id' => 'local_'.$organization->id,
                    'role' => MembershipRole::Admin,
                    'status' => MembershipStatus::Active,
                ]);
            }

            // Switch user to the new organization
            $organizationService->switchAccount($user, $organization->id);

            return to_route('organization.show');
        } catch (\Throwable $e) {
            Log::error('Failed to create organization', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors(['name' => __('Failed to create organization. Please try again.')]);
        }
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

        // Verify the user is an admin
        $isAdmin = OrganizationMembership::where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->where('role', MembershipRole::Admin)
            ->exists();

        if (! $isAdmin) {
            return back()->withErrors(['email' => __('Only admins can send invitations.')]);
        }

        try {
            $workosUserMgmt = new UserManagement;
            $workosUserMgmt->sendInvitation(
                email: $request->email,
                organizationId: $organization->workos_organization_id,
            );

            return back()->with('success', __('Invitation sent to :email', ['email' => $request->email]));
        } catch (\Throwable $e) {
            Log::error('Failed to send invitation', [
                'organization_id' => $organization->id,
                'email' => $request->email,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors(['email' => __('Failed to send invitation. Please try again.')]);
        }
    }
}
