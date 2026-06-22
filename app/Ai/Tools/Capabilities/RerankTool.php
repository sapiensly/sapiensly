<?php

namespace App\Ai\Tools\Capabilities;

use App\Services\Ai\AiCapabilities;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool as ToolContract;
use Laravel\Ai\Reranking;
use Laravel\Ai\Tools\Request as AiRequest;
use Stringable;

/**
 * Reorders a list of documents by relevance to a query using the model configured
 * under admin AI > Defaults → "Reranking".
 */
class RerankTool implements ToolContract
{
    public function __construct(private AiCapabilities $capabilities) {}

    public function description(): Stringable|string
    {
        return 'Reorder a list of candidate documents/passages by relevance to a query using the workspace\'s configured reranking model. Use to refine search or retrieval results. Returns the documents ranked best-first with scores.';
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('The query to rank the documents against.')->required(),
            'documents' => $schema->array()->items($schema->string())->description('The candidate documents/passages to rerank.')->required(),
            'limit' => $schema->integer()->description('Optional max number of results to return.'),
        ];
    }

    public function handle(AiRequest $request): Stringable|string
    {
        $args = $request->toArray();
        $query = trim((string) ($args['query'] ?? ''));
        $documents = array_values(array_filter(
            (array) ($args['documents'] ?? []),
            fn ($d) => is_string($d) && trim($d) !== '',
        ));

        if ($query === '' || $documents === []) {
            return 'Error: provide a query and a non-empty list of documents.';
        }

        $handler = $this->capabilities->resolve('reranking');
        if ($handler === null) {
            return 'Error: no reranking model is configured. Set one in admin AI > Defaults → Reranking.';
        }

        if ($handler['driver'] === 'openrouter') {
            return 'Error: reranking is not available through OpenRouter (it has no rerank endpoint). Configure a direct rerank provider (Cohere, Voyage AI or Jina) in admin AI > Defaults → Reranking.';
        }

        try {
            $limit = isset($args['limit']) ? (int) $args['limit'] : null;
            $response = Reranking::of($documents)
                ->limit($limit)
                ->rerank($query, $handler['provider'], $handler['model']);

            $lines = [];
            $rank = 1;
            foreach ($response->results as $result) {
                $score = number_format((float) $result->score, 3);
                $lines[] = "{$rank}. (score {$score}) ".$result->document;
                $rank++;
            }

            return "Reranked with {$handler['model']}:\n".implode("\n", $lines);
        } catch (\Throwable $e) {
            return 'Error reranking documents: '.$e->getMessage();
        }
    }
}
