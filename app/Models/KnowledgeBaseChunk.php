<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pgvector\Laravel\Vector;

class KnowledgeBaseChunk extends Model
{
    use HasFactory;

    protected $fillable = [
        'knowledge_base_document_id',
        'document_id',
        'knowledge_base_id',
        'content',
        'chunk_index',
        'metadata',
        'embedding',
        'embedding_model',
    ];

    protected function casts(): array
    {
        return [
            'chunk_index' => 'integer',
            'metadata' => 'array',
            'embedding' => Vector::class,
        ];
    }

    /**
     * Legacy document relationship (KnowledgeBaseDocument)
     *
     * @deprecated Use sourceDocument() for new Document model
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBaseDocument::class, 'knowledge_base_document_id');
    }

    /**
     * New document relationship (Document model)
     */
    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }
}
