<?php

namespace App\Observers;

use App\Models\OrganizationMembership;
use Spatie\Permission\PermissionRegistrar;

class OrganizationMembershipObserver
{
    public function created(OrganizationMembership $membership): void
    {
        if (! $membership->user) {
            return;
        }

        $this->syncRole($membership);
    }

    public function updated(OrganizationMembership $membership): void
    {
        if (! $membership->user) {
            return;
        }

        if ($membership->isDirty('role') || $membership->isDirty('status')) {
            $this->syncRole($membership);
        }
    }

    public function deleted(OrganizationMembership $membership): void
    {
        if (! $membership->user) {
            return;
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($membership->organization_id);
        $membership->user->syncRoles([]);
    }

    private function syncRole(OrganizationMembership $membership): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($membership->organization_id);

        if ($membership->isActive()) {
            $membership->user->syncRoles([$membership->role->value]);
        } else {
            $membership->user->syncRoles([]);
        }
    }
}
