<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('documents.view');
    }

    public function view(User $user, Document $document): bool
    {
        if (! $document->isVisibleTo($user)) {
            return false;
        }

        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('documents.view');
    }

    public function create(User $user): bool
    {
        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('documents.create');
    }

    public function update(User $user, Document $document): bool
    {
        if (! $user->organization_id) {
            return $document->isOwnedBy($user);
        }

        if (! $document->isVisibleTo($user)) {
            return false;
        }

        return $user->hasPermissionTo('documents.update') && $document->isOwnedBy($user);
    }

    public function delete(User $user, Document $document): bool
    {
        if (! $user->organization_id) {
            return $document->isOwnedBy($user);
        }

        if (! $document->isVisibleTo($user)) {
            return false;
        }

        return $user->hasPermissionTo('documents.delete') && $document->isOwnedBy($user);
    }

    public function restore(User $user, Document $document): bool
    {
        if (! $user->organization_id) {
            return $document->isOwnedBy($user);
        }

        if (! $document->isVisibleTo($user)) {
            return false;
        }

        return $user->hasPermissionTo('documents.update') && $document->isOwnedBy($user);
    }

    public function forceDelete(User $user, Document $document): bool
    {
        if (! $user->organization_id) {
            return $document->isOwnedBy($user);
        }

        if (! $document->isVisibleTo($user)) {
            return false;
        }

        return $user->hasPermissionTo('documents.delete') && $document->isOwnedBy($user);
    }

    public function download(User $user, Document $document): bool
    {
        if (! $document->isVisibleTo($user)) {
            return false;
        }

        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('documents.download');
    }

    public function move(User $user, Document $document): bool
    {
        if (! $user->organization_id) {
            return $document->isOwnedBy($user);
        }

        if (! $document->isVisibleTo($user)) {
            return false;
        }

        return $user->hasPermissionTo('documents.update') && $document->isOwnedBy($user);
    }
}
