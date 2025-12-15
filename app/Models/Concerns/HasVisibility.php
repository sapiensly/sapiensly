<?php

namespace App\Models\Concerns;

use App\Enums\Visibility;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait HasVisibility
{
    public function initializeHasVisibility(): void
    {
        // Add visibility and organization_id to fillable if using HasVisibility
        if (! in_array('visibility', $this->fillable)) {
            $this->fillable[] = 'visibility';
        }
        if (! in_array('organization_id', $this->fillable)) {
            $this->fillable[] = 'organization_id';
        }
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Scope: Resources visible to a user (own + organization shared)
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function ($q) use ($user) {
            // User's own resources
            $q->where('user_id', $user->id);

            // Organization resources if user belongs to one
            if ($user->organization_id) {
                $q->orWhere(function ($orgQ) use ($user) {
                    $orgQ->where('organization_id', $user->organization_id)
                        ->where('visibility', Visibility::Organization);
                });
            }
        });
    }

    /**
     * Scope: Resources owned by user
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    /**
     * Scope: Resources shared with organization
     */
    public function scopeSharedWithOrganization(Builder $query, Organization $organization): Builder
    {
        return $query->where('organization_id', $organization->id)
            ->where('visibility', Visibility::Organization);
    }

    /**
     * Check if the resource is owned by the user
     */
    public function isOwnedBy(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    /**
     * Check if the resource is visible to the user
     */
    public function isVisibleTo(User $user): bool
    {
        // Owner can always see
        if ($this->user_id === $user->id) {
            return true;
        }

        // Organization members can see organization-visible resources
        if ($this->visibility === Visibility::Organization
            && $user->organization_id
            && $this->organization_id === $user->organization_id) {
            return true;
        }

        return false;
    }

    /**
     * Check if the resource is shared with organization
     */
    public function isSharedWithOrganization(): bool
    {
        return $this->visibility === Visibility::Organization && $this->organization_id !== null;
    }

    /**
     * Update visibility and set organization_id accordingly
     */
    public function updateVisibility(Visibility $visibility, User $user): static
    {
        $organizationId = null;

        if ($visibility === Visibility::Organization) {
            if (! $user->organization_id) {
                throw new \RuntimeException('User must belong to an organization to share resources.');
            }
            $organizationId = $user->organization_id;
        }

        $this->update([
            'visibility' => $visibility,
            'organization_id' => $organizationId,
        ]);

        return $this->fresh();
    }
}
