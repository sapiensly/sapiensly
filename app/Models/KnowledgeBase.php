<?php

namespace App\Models;

use App\Enums\KnowledgeBaseStatus;
use App\Enums\Visibility;
use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\HasVisibility;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class KnowledgeBase extends Model
{
    use HasFactory, HasPrefixedUlid, HasVisibility, SoftDeletes;

    protected $fillable = [
        'user_id',
        'organization_id',
        'name',
        'description',
        'keywords',
        'status',
        'visibility',
        'config',
        'document_count',
        'chunk_count',
    ];

    protected function casts(): array
    {
        return [
            'status' => KnowledgeBaseStatus::class,
            'visibility' => Visibility::class,
            'keywords' => 'array',
            'config' => 'array',
            'document_count' => 'integer',
            'chunk_count' => 'integer',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'kb';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Legacy document relationship (KnowledgeBaseDocument)
     *
     * @deprecated Use attachedDocuments() for new Document model
     */
    public function documents(): HasMany
    {
        return $this->hasMany(KnowledgeBaseDocument::class);
    }

    /**
     * New document relationship (Document model via pivot)
     */
    public function attachedDocuments(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'document_knowledge_base')
            ->withPivot(['embedding_status', 'error_message'])
            ->withTimestamps();
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeBaseChunk::class);
    }

    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class, 'agent_knowledge_bases')
            ->withTimestamps();
    }

    public function isReady(): bool
    {
        return $this->status === KnowledgeBaseStatus::Ready;
    }
}
