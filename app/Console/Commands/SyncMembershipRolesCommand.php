<?php

namespace App\Console\Commands;

use App\Enums\MembershipStatus;
use App\Models\OrganizationMembership;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Spatie\Permission\PermissionRegistrar;

#[Signature('app:sync-membership-roles')]
#[Description('Sync existing organization memberships to Spatie roles')]
class SyncMembershipRolesCommand extends Command
{
    public function handle(): int
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $memberships = OrganizationMembership::query()
            ->where('status', MembershipStatus::Active)
            ->with('user')
            ->get();

        $synced = 0;

        foreach ($memberships as $membership) {
            if (! $membership->user) {
                continue;
            }

            setPermissionsTeamId($membership->organization_id);
            $membership->user->syncRoles([$membership->role->value]);
            $synced++;
        }

        $this->info("Synced {$synced} membership(s) to Spatie roles.");

        return self::SUCCESS;
    }
}
