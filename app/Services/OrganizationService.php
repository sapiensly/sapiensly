<?php

namespace App\Services;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use WorkOS\Organizations;
use WorkOS\UserManagement;

class OrganizationService
{
    protected UserManagement $userManagement;

    protected Organizations $organizations;

    public function __construct()
    {
        $this->userManagement = new UserManagement;
        $this->organizations = new Organizations;
    }

    /**
     * Sync organization memberships for a user on login.
     */
    public function syncUserMemberships(User $user): void
    {
        if (! $user->workos_id) {
            return;
        }

        try {
            [$before, $after, $memberships] = $this->userManagement->listOrganizationMemberships(
                userId: $user->workos_id,
                statuses: ['active', 'pending'],
                limit: 100
            );

            DB::transaction(function () use ($user, $memberships) {
                // Get current WorkOS membership IDs
                $currentWorkOsIds = collect($memberships)
                    ->pluck('id')
                    ->toArray();

                // Deactivate memberships no longer in WorkOS
                OrganizationMembership::where('user_id', $user->id)
                    ->whereNotIn('workos_membership_id', $currentWorkOsIds)
                    ->update(['status' => MembershipStatus::Inactive]);

                foreach ($memberships as $workosMembership) {
                    $this->syncMembership($user, $workosMembership);
                }

                // Set user's primary organization (first active admin, or first active)
                $this->setUserPrimaryOrganization($user);
            });
        } catch (\Throwable $e) {
            Log::error('Failed to sync organization memberships', [
                'user_id' => $user->id,
                'workos_id' => $user->workos_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Sync a single membership from WorkOS.
     */
    protected function syncMembership(User $user, \WorkOS\Resource\OrganizationMembership $workosMembership): OrganizationMembership
    {
        // Ensure organization exists locally
        $organization = $this->findOrCreateOrganization($workosMembership->organizationId);

        $status = match ($workosMembership->status) {
            'active' => MembershipStatus::Active,
            'pending' => MembershipStatus::Pending,
            default => MembershipStatus::Inactive,
        };

        return OrganizationMembership::updateOrCreate(
            ['workos_membership_id' => $workosMembership->id],
            [
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'role' => $this->mapWorkOsRole($workosMembership->role),
                'status' => $status,
            ]
        );
    }

    /**
     * Find or create organization from WorkOS.
     */
    protected function findOrCreateOrganization(string $workosOrgId): Organization
    {
        $organization = Organization::where('workos_organization_id', $workosOrgId)->first();

        if (! $organization) {
            // Fetch organization details from WorkOS
            $workosOrg = $this->organizations->getOrganization($workosOrgId);

            $organization = Organization::create([
                'workos_organization_id' => $workosOrgId,
                'name' => $workosOrg->name,
                'slug' => $workosOrg->slug ?? null,
            ]);
        }

        return $organization;
    }

    /**
     * Switch the user's active account context.
     *
     * Pass null to switch to personal mode.
     * Pass an organization ID to switch to that org's context.
     */
    public function switchAccount(User $user, ?string $organizationId): void
    {
        if ($organizationId === null) {
            $user->update(['organization_id' => null]);

            return;
        }

        $hasActiveMembership = OrganizationMembership::where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->where('status', MembershipStatus::Active)
            ->exists();

        if (! $hasActiveMembership) {
            throw new \RuntimeException('User does not have an active membership in this organization.');
        }

        $user->update(['organization_id' => $organizationId]);
    }

    /**
     * Set user's primary organization.
     *
     * Preserves user's current organization_id if the membership is still active.
     * Only clears if the current membership was deactivated.
     * New users stay in personal mode (null).
     */
    protected function setUserPrimaryOrganization(User $user): void
    {
        if ($user->organization_id) {
            $currentMembershipActive = OrganizationMembership::where('user_id', $user->id)
                ->where('organization_id', $user->organization_id)
                ->where('status', MembershipStatus::Active)
                ->exists();

            if ($currentMembershipActive) {
                return;
            }
        }

        $user->update(['organization_id' => null]);
    }

    /**
     * Map WorkOS role to local enum.
     */
    protected function mapWorkOsRole($workosRole): MembershipRole
    {
        if (! $workosRole) {
            return MembershipRole::Member;
        }

        // WorkOS role is a RoleResponse object with a slug property
        $roleSlug = is_object($workosRole) ? $workosRole->slug : $workosRole;

        return match ($roleSlug) {
            'admin', 'owner' => MembershipRole::Admin,
            default => MembershipRole::Member,
        };
    }

    /**
     * Get the organization for a user.
     */
    public function getUserOrganization(User $user): ?Organization
    {
        if (! $user->organization_id) {
            return null;
        }

        return $user->organization;
    }

    /**
     * Check if a user belongs to an organization.
     */
    public function userBelongsToOrganization(User $user, Organization $organization): bool
    {
        return OrganizationMembership::where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->where('status', MembershipStatus::Active)
            ->exists();
    }
}
