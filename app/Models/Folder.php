<?php

namespace App\Models;

use App\Enums\Visibility;
use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\HasVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Folder extends Model
{
    use HasPrefixedUlid, HasVisibility, SoftDeletes;

    protected $fillable = [
        'user_id',
        'organization_id',
        'parent_id',
        'name',
        'visibility',
    ];

    protected function casts(): array
    {
        return [
            'visibility' => Visibility::class,
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'folder';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Folder::class, 'parent_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * Scope: Root folders only (no parent)
     */
    public function scopeRoot(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Get all ancestors (for breadcrumbs)
     */
    public function getAncestors(): Collection
    {
        $ancestors = new Collection();
        $folder = $this->parent;

        while ($folder) {
            $ancestors->prepend($folder);
            $folder = $folder->parent;
        }

        return $ancestors;
    }

    /**
     * Get all descendants (for recursive operations)
     */
    public function getDescendants(): Collection
    {
        $descendants = new Collection();

        foreach ($this->children as $child) {
            $descendants->push($child);
            $descendants = $descendants->merge($child->getDescendants());
        }

        return $descendants;
    }

    /**
     * Check if this folder is a descendant of another folder
     * (Used to prevent circular references)
     */
    public function isDescendantOf(Folder $folder): bool
    {
        $parent = $this->parent;

        while ($parent) {
            if ($parent->id === $folder->id) {
                return true;
            }
            $parent = $parent->parent;
        }

        return false;
    }
}
