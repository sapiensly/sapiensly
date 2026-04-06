<?php

namespace App\Policies;

use App\Models\Folder;
use App\Models\User;

class FolderPolicy
{
    public function viewAny(User $user): bool
    {
        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('folders.view');
    }

    public function view(User $user, Folder $folder): bool
    {
        if (! $folder->isVisibleTo($user)) {
            return false;
        }

        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('folders.view');
    }

    public function create(User $user): bool
    {
        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('folders.create');
    }

    public function update(User $user, Folder $folder): bool
    {
        if (! $user->organization_id) {
            return $folder->isOwnedBy($user);
        }

        if (! $folder->isVisibleTo($user)) {
            return false;
        }

        return $user->hasPermissionTo('folders.update') && $folder->isOwnedBy($user);
    }

    public function delete(User $user, Folder $folder): bool
    {
        if (! $user->organization_id) {
            return $folder->isOwnedBy($user);
        }

        if (! $folder->isVisibleTo($user)) {
            return false;
        }

        return $user->hasPermissionTo('folders.delete') && $folder->isOwnedBy($user);
    }

    public function restore(User $user, Folder $folder): bool
    {
        if (! $user->organization_id) {
            return $folder->isOwnedBy($user);
        }

        if (! $folder->isVisibleTo($user)) {
            return false;
        }

        return $user->hasPermissionTo('folders.update') && $folder->isOwnedBy($user);
    }

    public function forceDelete(User $user, Folder $folder): bool
    {
        if (! $user->organization_id) {
            return $folder->isOwnedBy($user);
        }

        if (! $folder->isVisibleTo($user)) {
            return false;
        }

        return $user->hasPermissionTo('folders.delete') && $folder->isOwnedBy($user);
    }
}
