<?php

namespace App\Policies;

use App\Models\Agent;
use App\Models\User;

class AgentPolicy
{
    public function viewAny(User $user): bool
    {
        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('agents.view');
    }

    public function view(User $user, Agent $agent): bool
    {
        if (! $agent->isVisibleTo($user)) {
            return false;
        }

        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('agents.view');
    }

    public function create(User $user): bool
    {
        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('agents.create');
    }

    public function update(User $user, Agent $agent): bool
    {
        if (! $user->organization_id) {
            return $agent->isOwnedBy($user);
        }

        if (! $agent->isVisibleTo($user)) {
            return false;
        }

        return $user->hasPermissionTo('agents.update') && $agent->isOwnedBy($user);
    }

    public function delete(User $user, Agent $agent): bool
    {
        if (! $user->organization_id) {
            return $agent->isOwnedBy($user);
        }

        if (! $agent->isVisibleTo($user)) {
            return false;
        }

        return $user->hasPermissionTo('agents.delete') && $agent->isOwnedBy($user);
    }
}
