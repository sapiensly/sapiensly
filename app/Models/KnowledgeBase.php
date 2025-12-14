<?php

namespace App\Models;

use App\Enums\KnowledgeBaseStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class KnowledgeBase extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'status',
        'config',
        'document_count',
        'chunk_count',
    ];

    protected function casts(): array
    {
        return [
            'status' => KnowledgeBaseStatus::class,
            'config' => 'array',
            'document_count' => 'integer',
            'chunk_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(KnowledgeBaseDocument::class);
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

    public function updateCounts(): void
    {
        $this->update([
            'document_count' => $this->documents()->count(),
            'chunk_count' => $this->chunks()->count(),
        ]);
    }

    public function isReady(): bool
    {
        return $this->status === KnowledgeBaseStatus::Ready;
    }
}
