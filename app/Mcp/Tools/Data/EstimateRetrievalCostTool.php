<?php

namespace App\Mcp\Tools\Data;

use App\Mcp\Tools\SapiensTool;
use App\Models\KnowledgeBase;
use App\Services\RetrievalCostEstimator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Estimate the USD cost of answering a single RAG query against the tenant\'s knowledge bases: embedding the query plus reranking (when a KB opts in). The LLM answer generation is billed separately and not included.')]
class EstimateRetrievalCostTool extends SapiensTool
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

        $allowed = KnowledgeBase::query()->pluck('id');
        $kbIds = isset($validated['knowledge_base_ids'])
            ? $allowed->intersect($validated['knowledge_base_ids'])->values()
            : $allowed->values();

        $estimate = app(RetrievalCostEstimator::class)->estimate(
            $validated['query'],
            $kbIds->all(),
            $validated['top_k'] ?? 5,
        );

        return Response::json($estimate);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('The natural-language query to estimate retrieval cost for.')->required(),
            'knowledge_base_ids' => $schema->array()->description('Optional subset of knowledge base ids; defaults to all you can access.'),
            'top_k' => $schema->integer()->description('Number of chunks the query would return (default 5).'),
        ];
    }
}
