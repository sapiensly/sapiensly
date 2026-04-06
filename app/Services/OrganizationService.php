<?php

namespace App\Services;

use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

class OrganizationService
{
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
            setPermissionsTeamId(null);

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
        setPermissionsTeamId($organizationId);
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
