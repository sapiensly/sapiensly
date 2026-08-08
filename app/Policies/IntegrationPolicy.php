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

    /**
     * Connect MY OWN account to a connection the tenant already configured
     * (the per-user OAuth handshake).
     *
     * Deliberately NOT gated on any `integrations.*` permission: the people who
     * need this are the ones who never administer connections — the technician
     * opening a built app whose dashboard reads live, the person the builder
     * just asked to authorize. They see no secrets and change nothing shared;
     * the only thing produced is a token belonging to them. Tenant visibility is
     * the whole gate.
     */
    public function connect(User $user, Integration $integration): bool
    {
        return $integration->isVisibleTo($user) || $integration->visibility === Visibility::Global;
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
        return $user->isSysAdmin();
    }
}
