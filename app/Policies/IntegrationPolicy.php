<?php

namespace App\Policies;

use App\Enums\Visibility;
use App\Models\Integration;
use App\Models\User;

class IntegrationPolicy
{
    public function viewAny(User $user): bool
    {
        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('integrations.view');
    }

    public function view(User $user, Integration $integration): bool
    {
        if (! $integration->isVisibleTo($user) && $integration->visibility !== Visibility::Global) {
            return false;
        }

        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('integrations.view');
    }

    public function create(User $user): bool
    {
        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('integrations.create');
    }

    public function update(User $user, Integration $integration): bool
    {
        if ($integration->visibility === Visibility::Global) {
            return $this->manageGlobal($user);
        }

        if (! $user->organization_id) {
            return $integration->isOwnedBy($user);
        }

        if (! $integration->isVisibleTo($user)) {
            return false;
        }

        return $user->hasPermissionTo('integrations.update') && $integration->isOwnedBy($user);
    }

    public function delete(User $user, Integration $integration): bool
    {
        if ($integration->visibility === Visibility::Global) {
            return $this->manageGlobal($user);
        }

        if (! $user->organization_id) {
            return $integration->isOwnedBy($user);
        }

        if (! $integration->isVisibleTo($user)) {
            return false;
        }

        return $user->hasPermissionTo('integrations.delete') && $integration->isOwnedBy($user);
    }

    public function execute(User $user, Integration $integration): bool
    {
        if (! $integration->isVisibleTo($user) && $integration->visibility !== Visibility::Global) {
            return false;
        }

        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('integrations.execute');
    }

    public function manageGlobal(User $user): bool
    {
        return $user->hasRole('sysadmin');
    }
}
