<?php

namespace App\Services;

use App\Models\KnowledgeBase;
use App\Services\Ai\AiCapabilities;
use App\Services\Ai\AiPricing;
use Illuminate\Support\Collection;

/**
 * Estimates the USD cost of answering a single RAG query: embedding the query
 * (once per distinct embedding model across the searched KBs) plus, when a KB
 * opted into reranking and a rerank model is configured, the reranking pass over
 * the over-fetched candidate pool. The LLM answer generation is billed
 * separately and is out of scope here.
 */
class RetrievalCostEstimator
{
    /** Mirrors RetrievalService's over-fetch multiplier / cap. */
    private const RERANK_CANDIDATE_MULTIPLIER = 4;

    private const RERANK_MAX_CANDIDATES = 50;

    public function __construct(
        private AiPricing $pricing,
        private AiCapabilities $capabilities,
    ) {}

    /**
     * @param  array<int, string>  $knowledgeBaseIds
     * @return array{embedding_cost: float, rerank_cost: float, total_cost: float, rerank_model: ?string, currency: string, estimated: bool}
     */
    public function estimate(string $query, array $knowledgeBaseIds, int $topK = 5): array
    {
        $kbs = empty($knowledgeBaseIds)
            ? collect()
            : KnowledgeBase::query()->whereIn('id', $knowledgeBaseIds)->get();

        $queryTokens = (int) ceil(mb_strlen($query) / 4);

        $embeddingCost = $this->embeddingCost($kbs, $queryTokens);
        [$rerankCost, $rerankModel] = $this->rerankCost($kbs, $queryTokens, $topK);

        return [
            'embedding_cost' => round($embeddingCost, 6),
            'rerank_cost' => round($rerankCost, 6),
            'total_cost' => round($embeddingCost + $rerankCost, 6),
            'rerank_model' => $rerankModel,
            'currency' => 'USD',
            'estimated' => true,
        ];
    }

    /**
     * The query is embedded once per distinct embedding model (RetrievalService
     * groups KBs by embedding config), so sum the cost across distinct models.
     *
     * @param  Collection<int, KnowledgeBase>  $kbs
     */
    private function embeddingCost(Collection $kbs, int $queryTokens): float
    {
        $models = $kbs->isEmpty()
            ? collect([EmbeddingService::forKnowledgeBase(new KnowledgeBase)->getModel()])
            : $kbs->map(fn (KnowledgeBase $kb) => EmbeddingService::forKnowledgeBase($kb)->getModel())->unique();

        return $models->sum(function (string $model) use ($queryTokens): float {
            $price = $this->pricing->pricesFor($model);

            return $price === null ? 0.0 : $queryTokens * ($price['input'] / 1_000_000);
        });
    }

    /**
     * @param  Collection<int, KnowledgeBase>  $kbs
     * @return array{0: float, 1: ?string}
     */
    private function rerankCost(Collection $kbs, int $queryTokens, int $topK): array
    {
        $optedIn = $kbs->contains(fn (KnowledgeBase $kb) => (bool) data_get($kb->config, 'rerank', false));
        if (! $optedIn) {
            return [0.0, null];
        }

        $handler = $this->capabilities->resolve('reranking');
        if ($handler === null || ($handler['driver'] ?? null) === 'openrouter') {
            return [0.0, null];
        }

        $candidates = min($topK * self::RERANK_CANDIDATE_MULTIPLIER, self::RERANK_MAX_CANDIDATES);
        $inputTokens = $queryTokens + $candidates * (int) config('ai.ingestion.avg_chunk_tokens', 200);

        return [$this->pricing->costForRerank($handler['model'], 1, $inputTokens), $handler['model']];
    }
}
