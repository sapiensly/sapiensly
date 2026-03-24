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
     * Scope: Resources for the user's current account context (fully isolated).
     *
     * Personal mode: only user's own resources with no org.
     * Business mode: user's own private resources in this org + all shared resources in this org.
     */
    public function scopeForAccountContext(Builder $query, User $user): Builder
    {
        if ($user->organization_id === null) {
            return $query->where('user_id', $user->id)->whereNull('organization_id');
        }

        return $query->where('organization_id', $user->organization_id)
            ->where(fn (Builder $q) => $q
                ->where('user_id', $user->id)
                ->orWhere('visibility', Visibility::Organization));
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
     * Check if the resource is visible to the user in their current account context.
     */
    public function isVisibleTo(User $user): bool
    {
        if ($user->organization_id === null) {
            // Personal context: only own resources with no org
            return $this->user_id === $user->id && $this->organization_id === null;
        }

        // Business context: resource must belong to the same org
        if ($this->organization_id !== $user->organization_id) {
            return false;
        }

        // Own resources or organization-shared resources
        return $this->user_id === $user->id || $this->visibility === Visibility::Organization;
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
