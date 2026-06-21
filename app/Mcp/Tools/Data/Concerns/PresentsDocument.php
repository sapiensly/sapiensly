<?php

namespace App\Mcp\Tools\Data\Concerns;

use App\Models\Document;
use App\Models\KnowledgeBase;

/**
 * Shared presentation for the document management MCP tools.
 */
trait PresentsDocument
{
    /**
     * The JSON shape returned for a single document. Inline documents include
     * their body; file-backed documents expose the filename/size instead.
     *
     * @return array<string, mixed>
     */
    protected function documentPayload(Document $doc): array
    {
        return [
            'id' => $doc->id,
            'name' => $doc->name,
            'type' => $doc->type?->value,
            'visibility' => $doc->visibility?->value,
            'is_inline' => $doc->isInline(),
            'original_filename' => $doc->original_filename,
            'file_size' => $doc->file_size,
            'keywords' => $doc->keywords ?? [],
            'body' => $doc->isInline() ? $doc->body : null,
            'knowledge_bases' => $doc->knowledgeBases()->get()->map(fn (KnowledgeBase $kb) => [
                'id' => $kb->id,
                'name' => $kb->name,
                'embedding_status' => $kb->pivot->embedding_status ?? null,
                'error_message' => $kb->pivot->error_message ?? null,
            ])->values(),
        ];
    }
}
