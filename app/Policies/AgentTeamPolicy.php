<?php

namespace App\Policies;

use App\Models\AgentTeam;
use App\Models\User;

class AgentTeamPolicy
{
    public function viewAny(User $user): bool
    {
        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('agent-teams.view');
    }

    public function view(User $user, AgentTeam $agentTeam): bool
    {
        if (! $agentTeam->isVisibleTo($user)) {
            return false;
        }

        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('agent-teams.view');
    }

    public function create(User $user): bool
    {
        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('agent-teams.create');
    }

    public function update(User $user, AgentTeam $agentTeam): bool
    {
        if (! $user->organization_id) {
            return $agentTeam->isOwnedBy($user);
        }

        if (! $agentTeam->isVisibleTo($user)) {
            return false;
        }

        return $user->hasPermissionTo('agent-teams.update') && $agentTeam->isOwnedBy($user);
    }

    public function delete(User $user, AgentTeam $agentTeam): bool
    {
        if (! $user->organization_id) {
            return $agentTeam->isOwnedBy($user);
        }

        if (! $agentTeam->isVisibleTo($user)) {
            return false;
        }

        return $user->hasPermissionTo('agent-teams.delete') && $agentTeam->isOwnedBy($user);
    }
}
