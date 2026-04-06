<?php

namespace App\Policies;

use App\Models\AiProvider;
use App\Models\User;

class AiProviderPolicy
{
    public function viewAny(User $user): bool
    {
        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('ai-providers.view');
    }

    public function view(User $user, AiProvider $aiProvider): bool
    {
        if (! $aiProvider->isVisibleTo($user)) {
            return false;
        }

        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('ai-providers.view');
    }

    public function create(User $user): bool
    {
        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('ai-providers.create');
    }

    public function update(User $user, AiProvider $aiProvider): bool
    {
        if (! $user->organization_id) {
            return $aiProvider->isOwnedBy($user);
        }

        if (! $aiProvider->isVisibleTo($user)) {
            return false;
        }

        return $user->hasPermissionTo('ai-providers.update') && $aiProvider->isOwnedBy($user);
    }

    public function delete(User $user, AiProvider $aiProvider): bool
    {
        if (! $user->organization_id) {
            return $aiProvider->isOwnedBy($user);
        }

        if (! $aiProvider->isVisibleTo($user)) {
            return false;
        }

        return $user->hasPermissionTo('ai-providers.delete') && $aiProvider->isOwnedBy($user);
    }
}
