<?php

namespace App\Policies;

use App\Models\BotFlow;
use App\Models\User;

class BotFlowPolicy
{
    public function viewAny(User $user): bool
    {
        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('flows.view');
    }

    public function view(User $user, BotFlow $flow): bool
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

    public function update(User $user, BotFlow $flow): bool
    {
        if (! $user->organization_id) {
            return $flow->isOwnedBy($user);
        }

        if (! $flow->isVisibleTo($user)) {
            return false;
        }

        return $user->hasPermissionTo('flows.update') && $flow->isOwnedBy($user);
    }

    public function delete(User $user, BotFlow $flow): bool
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
