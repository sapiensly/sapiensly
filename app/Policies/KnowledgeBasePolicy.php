<?php

namespace App\Policies;

use App\Models\KnowledgeBase;
use App\Models\User;

class KnowledgeBasePolicy
{
    public function viewAny(User $user): bool
    {
        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('knowledge-bases.view');
    }

    public function view(User $user, KnowledgeBase $knowledgeBase): bool
    {
        if (! $knowledgeBase->isVisibleTo($user)) {
            return false;
        }

        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('knowledge-bases.view');
    }

    public function create(User $user): bool
    {
        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('knowledge-bases.create');
    }

    public function update(User $user, KnowledgeBase $knowledgeBase): bool
    {
        if (! $user->organization_id) {
            return $knowledgeBase->isOwnedBy($user);
        }

        if (! $knowledgeBase->isVisibleTo($user)) {
            return false;
        }

        return $user->hasPermissionTo('knowledge-bases.update') && $knowledgeBase->isOwnedBy($user);
    }

    public function delete(User $user, KnowledgeBase $knowledgeBase): bool
    {
        if (! $user->organization_id) {
            return $knowledgeBase->isOwnedBy($user);
        }

        if (! $knowledgeBase->isVisibleTo($user)) {
            return false;
        }

        return $user->hasPermissionTo('knowledge-bases.delete') && $knowledgeBase->isOwnedBy($user);
    }
}
