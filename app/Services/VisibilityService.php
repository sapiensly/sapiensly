<?php

namespace App\Services;

use App\Enums\Visibility;
use App\Models\Concerns\HasVisibility;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class VisibilityService
{
    /**
     * Update visibility for any model that uses HasVisibility trait.
     *
     * @param  Model&HasVisibility  $resource
     */
    public function updateVisibility(Model $resource, Visibility $visibility, User $user): Model
    {
        // Verify the model uses HasVisibility trait
        if (! in_array(HasVisibility::class, class_uses_recursive($resource))) {
            throw new \InvalidArgumentException('Model must use HasVisibility trait.');
        }

        // Verify user owns the resource
        if (! $resource->isOwnedBy($user)) {
            throw new \RuntimeException('User does not own this resource.');
        }

        return $resource->updateVisibility($visibility, $user);
    }

    /**
     * Check if user can access a resource.
     *
     * @param  Model&HasVisibility  $resource
     */
    public function canAccess(Model $resource, User $user): bool
    {
        if (! in_array(HasVisibility::class, class_uses_recursive($resource))) {
            throw new \InvalidArgumentException('Model must use HasVisibility trait.');
        }

        return $resource->isVisibleTo($user);
    }

    /**
     * Check if user can modify visibility of a resource.
     * Only owners can modify visibility.
     *
     * @param  Model&HasVisibility  $resource
     */
    public function canModifyVisibility(Model $resource, User $user): bool
    {
        if (! in_array(HasVisibility::class, class_uses_recursive($resource))) {
            throw new \InvalidArgumentException('Model must use HasVisibility trait.');
        }

        return $resource->isOwnedBy($user);
    }

    /**
     * Check if user can share a resource with their organization.
     *
     * @param  Model&HasVisibility  $resource
     */
    public function canShareWithOrganization(Model $resource, User $user): bool
    {
        if (! in_array(HasVisibility::class, class_uses_recursive($resource))) {
            throw new \InvalidArgumentException('Model must use HasVisibility trait.');
        }

        // Must own the resource and belong to an organization
        return $resource->isOwnedBy($user) && $user->hasOrganization();
    }
}
