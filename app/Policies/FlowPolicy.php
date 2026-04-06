<?php

namespace App\Policies;

use App\Models\Flow;
use App\Models\User;

class FlowPolicy
{
    public function viewAny(User $user): bool
    {
        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('flows.view');
    }

    public function view(User $user, Flow $flow): bool
    {
        if (! $flow->isVisibleTo($user)) {
            return false;
        }

        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('flows.view');
    }

    public function create(User $user): bool
    {
        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('flows.create');
    }

    public function update(User $user, Flow $flow): bool
    {
        if (! $user->organization_id) {
            return $flow->isOwnedBy($user);
        }

        if (! $flow->isVisibleTo($user)) {
            return false;
        }

        return $user->hasPermissionTo('flows.update') && $flow->isOwnedBy($user);
    }

    public function delete(User $user, Flow $flow): bool
    {
        if (! $user->organization_id) {
            return $flow->isOwnedBy($user);
        }

        if (! $flow->isVisibleTo($user)) {
            return false;
        }

        return $user->hasPermissionTo('flows.delete') && $flow->isOwnedBy($user);
    }
}
