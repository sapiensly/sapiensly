<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Enums\Visibility;
use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\HasVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use HasPrefixedUlid, HasVisibility, SoftDeletes;

    protected $fillable = [
        'user_id',
        'organization_id',
        'folder_id',
        'name',
        'keywords',
        'type',
        'original_filename',
        'file_path',
        'file_size',
        'body',
        'visibility',
        'metadata',
    ];

    protected $appends = [
        'formatted_file_size',
    ];

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'visibility' => Visibility::class,
            'keywords' => 'array',
            'file_size' => 'integer',
            'metadata' => 'array',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'doc';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    public function knowledgeBases(): BelongsToMany
    {
        return $this->belongsToMany(KnowledgeBase::class, 'document_knowledge_base')
            ->withPivot(['embedding_status', 'error_message'])
            ->withTimestamps();
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeBaseChunk::class);
    }

    /**
     * Inline-authored documents store their content in `body` and have no
     * companion file on disk.
     */
    public function isInline(): bool
    {
        return $this->body !== null && $this->file_path === null;
    }

    /**
     * Scope: Documents in a specific folder (or root if null)
     */
    public function scopeInFolder(Builder $query, ?string $folderId): Builder
    {
        if ($folderId === null) {
            return $query->whereNull('folder_id');
        }

        return $query->where('folder_id', $folderId);
    }

    /**
     * Get formatted file size
     */
    public function getFormattedFileSizeAttribute(): string
    {
        if (! $this->file_size) {
            return '-';
        }

        $bytes = $this->file_size;

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / (1024 * 1024), 1).' MB';
    }
}
