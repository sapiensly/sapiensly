<?php

namespace App\Mcp\Tools\Data\Concerns;

use App\Models\Document;
use App\Models\KnowledgeBase;

/**
 * Shared presentation for the knowledge base management MCP tools.
 */
trait PresentsKnowledgeBase
{
    /**
     * The JSON shape returned for a single knowledge base. When $withDocuments
     * is true the attached documents (with per-KB embedding status) are
     * included — used by get_knowledge_base.
     *
     * @return array<string, mixed>
     */
    protected function knowledgeBasePayload(KnowledgeBase $kb, bool $withDocuments = false): array
    {
        $payload = [
            'id' => $kb->id,
            'name' => $kb->name,
            'description' => $kb->description,
            'keywords' => $kb->keywords ?? [],
            'status' => $kb->status?->value,
            'visibility' => $kb->visibility?->value,
            'config' => $kb->config ?? [],
            'document_count' => $kb->document_count,
            'chunk_count' => $kb->chunk_count,
        ];

        if ($withDocuments) {
            $payload['documents'] = $kb->attachedDocuments()->get()->map(fn (Document $doc) => [
                'id' => $doc->id,
                'name' => $doc->name,
                'type' => $doc->type?->value,
                'embedding_status' => $doc->pivot->embedding_status ?? null,
            ])->values();
        }

        return $payload;
    }
}
