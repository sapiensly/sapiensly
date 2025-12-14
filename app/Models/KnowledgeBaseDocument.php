<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Enums\KnowledgeBaseStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeBaseDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'knowledge_base_id',
        'type',
        'source',
        'original_filename',
        'content',
        'metadata',
        'embedding_status',
        'error_message',
        'file_path',
        'file_size',
    ];

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'embedding_status' => KnowledgeBaseStatus::class,
            'metadata' => 'array',
            'file_size' => 'integer',
        ];
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeBaseChunk::class);
    }

    public function isProcessed(): bool
    {
        return $this->embedding_status === KnowledgeBaseStatus::Ready;
    }

    public function hasFailed(): bool
    {
        return $this->embedding_status === KnowledgeBaseStatus::Failed;
    }
}
