<?php

namespace App\Mcp\Tools\Data;

use App\Mcp\Tools\SapiensTool;
use App\Models\KnowledgeBase;
use App\Services\RetrievalService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Search the tenant\'s knowledge bases (RAG) for chunks relevant to a query. Returns the most similar chunks and a ready-to-use context block.')]
class SearchKnowledgeTool extends SapiensTool
{
    protected const ABILITY = 'data:read';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'max:2000'],
            'knowledge_base_ids' => ['sometimes', 'array'],
            'knowledge_base_ids.*' => ['string'],
            'top_k' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);

        // KnowledgeBase is a tenant/RLS table, so the query is already scoped to
        // the caller's tenant. Intersect with any explicitly requested ids so a
        // token can't reach an id outside its scope.
        $allowed = KnowledgeBase::query()->pluck('id');
        $kbIds = isset($validated['knowledge_base_ids'])
            ? $allowed->intersect($validated['knowledge_base_ids'])->values()
            : $allowed->values();

        if ($kbIds->isEmpty()) {
            return Response::error('No knowledge bases are available to search.');
        }

        $result = app(RetrievalService::class)->retrieve(
            $validated['query'],
            $kbIds->all(),
            $validated['top_k'] ?? 5,
        );

        return Response::json([
            'chunk_count' => $result['chunk_count'],
            'context' => $result['context'],
            'knowledge_bases' => $result['knowledge_bases'],
            'chunks' => collect($result['chunks'])->map(fn ($c) => [
                'content' => $c->content,
                'knowledge_base_id' => $c->knowledge_base_id,
                // Reranking score when the KB opted into reranking, else the
                // vector cosine similarity (1 - distance).
                'score' => isset($c->rerank_score)
                    ? $c->rerank_score
                    : (isset($c->distance) ? round(1 - (float) $c->distance, 4) : null),
            ])->values(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('The natural-language query to search for.')->required(),
            'knowledge_base_ids' => $schema->array()->description('Optional subset of knowledge base ids to search; defaults to all you can access.'),
            'top_k' => $schema->integer()->description('Number of chunks to return (default 5).'),
        ];
    }
}
