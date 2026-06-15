<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

class OrganizationPolicy
{
    /**
     * Only an active Owner of the organization may manage its SSO connection.
     * Sysadmins are already short-circuited by the Gate::before hook in
     * AppServiceProvider, so this check is purely the owner gate.
     */
    public function manageSso(User $user, Organization $organization): bool
    {
        return $this->isActiveOwner($user, $organization);
    }

    /**
     * Only an active Owner may view the org's AI spend and manage its budget
     * (sysadmins are short-circuited by the Gate::before hook).
     */
    public function viewAiSpend(User $user, Organization $organization): bool
    {
        return $this->isActiveOwner($user, $organization);
    }

    private function isActiveOwner(User $user, Organization $organization): bool
    {
        return OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->where('role', MembershipRole::Owner)
            ->where('status', MembershipStatus::Active)
            ->exists();
    }
}
