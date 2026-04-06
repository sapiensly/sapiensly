<?php

namespace App\Policies;

use App\Models\Tool;
use App\Models\User;

class ToolPolicy
{
    public function viewAny(User $user): bool
    {
        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('tools.view');
    }

    public function view(User $user, Tool $tool): bool
    {
        if (! $tool->isVisibleTo($user)) {
            return false;
        }

        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('tools.view');
    }

    public function create(User $user): bool
    {
        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('tools.create');
    }

    public function update(User $user, Tool $tool): bool
    {
        if (! $user->organization_id) {
            return $tool->isOwnedBy($user);
        }

        if (! $tool->isVisibleTo($user)) {
            return false;
        }

        return $user->hasPermissionTo('tools.update') && $tool->isOwnedBy($user);
    }

    public function delete(User $user, Tool $tool): bool
    {
        if (! $user->organization_id) {
            return $tool->isOwnedBy($user);
        }

        if (! $tool->isVisibleTo($user)) {
            return false;
        }

        return $user->hasPermissionTo('tools.delete') && $tool->isOwnedBy($user);
    }
}
