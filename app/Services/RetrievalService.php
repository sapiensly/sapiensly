<?php

namespace App\Services;

use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeBaseChunk;
use App\Models\KnowledgeBaseDocument;
use App\Services\Ai\AiCapabilities;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Reranking;

class RetrievalService
{
    /**
     * When reranking is enabled we over-fetch this many times topK candidates
     * by vector similarity, then let the reranking model pick the final topK.
     */
    private const RERANK_CANDIDATE_MULTIPLIER = 4;

    /** Hard cap on the candidate pool sent to the reranking model. */
    private const RERANK_MAX_CANDIDATES = 50;

    /**
     * An explicit embedding service overrides per-KB resolution (used by
     * searchForKnowledgeBase and tests). When null — the default for every app
     * caller — the query is embedded with each KB's OWN embedding configuration
     * at query time (see search()).
     */
    private ?EmbeddingService $embeddingService;

    private VectorStoreService $vectorStoreService;

    private AiCapabilities $capabilities;

    public function __construct(
        ?EmbeddingService $embeddingService = null,
        ?VectorStoreService $vectorStoreService = null,
        ?AiCapabilities $capabilities = null,
    ) {
        $this->embeddingService = $embeddingService;
        $this->vectorStoreService = $vectorStoreService ?? app(VectorStoreService::class);
        $this->capabilities = $capabilities ?? app(AiCapabilities::class);
    }

    /**
     * Search for relevant chunks across knowledge bases. Routing to the
     * correct database connection per KB is handled by VectorStoreService.
     *
     * The query MUST be embedded with the same embedding configuration its
     * chunks were embedded with (ingestion uses EmbeddingService::forKnowledgeBase):
     * a query embedded with a different provider/model/dimensions lands in a
     * different vector space and matches nothing. This is why documents added to
     * a KB after its embedding provider was configured/changed could become
     * invisible — the query kept using the global-default model. So, absent an
     * explicit override, we group the KBs by embedding config and embed the query
     * once per group with that group's configuration.
     *
     * @param  array<int, string>  $knowledgeBaseIds
     * @return Collection<int, KnowledgeBaseChunk>
     */
    public function search(
        string $query,
        array $knowledgeBaseIds,
        int $topK = 5,
        float $threshold = 0.7,
    ): Collection {
        if (empty($knowledgeBaseIds)) {
            return collect();
        }

        // Explicit override: one embedding config for the whole query.
        if ($this->embeddingService !== null) {
            return $this->vectorStoreService->searchSimilar(
                $knowledgeBaseIds,
                $this->embeddingService->embed($query),
                $topK,
                $threshold,
            );
        }

        $kbs = KnowledgeBase::query()->whereIn('id', $knowledgeBaseIds)->get();
        if ($kbs->isEmpty()) {
            return collect();
        }

        // Group KBs sharing an embedding configuration so we embed the query
        // once per distinct config and search each group with the matching vector.
        $groups = [];
        foreach ($kbs as $kb) {
            $service = EmbeddingService::forKnowledgeBase($kb);
            $signature = $service->getProvider().'|'.$service->getModel().'|'.$service->getDimensions();
            $groups[$signature] ??= ['service' => $service, 'ids' => []];
            $groups[$signature]['ids'][] = $kb->id;
        }

        $results = collect();
        foreach ($groups as $group) {
            $results = $results->concat($this->vectorStoreService->searchSimilar(
                $group['ids'],
                $group['service']->embed($query),
                $topK,
                $threshold,
            ));
        }

        return count($groups) > 1
            ? $results->sortBy('distance')->take($topK)->values()
            : $results;
    }

    /**
     * Search using a specific KnowledgeBase's embedding configuration.
     *
     * @return Collection<int, KnowledgeBaseChunk>
     */
    public function searchForKnowledgeBase(
        string $query,
        KnowledgeBase $knowledgeBase,
        int $topK = 5,
        float $threshold = 0.7,
    ): Collection {
        $embeddingService = EmbeddingService::forKnowledgeBase($knowledgeBase);

        $retriever = new self($embeddingService, $this->vectorStoreService, $this->capabilities);

        return $retriever->search($query, [$knowledgeBase->id], $topK, $threshold);
    }

    /**
     * Build a context string from retrieved chunks. The $chunks collection
     * may come from a tenant connection, so we hydrate Document / KB metadata
     * from the application database in a single batched pass instead of
     * relying on Eloquent's lazy relations (which would try to query across
     * the wrong connection).
     *
     * @param  Collection<int, KnowledgeBaseChunk>  $chunks
     */
    public function buildContext(Collection $chunks): string
    {
        if ($chunks->isEmpty()) {
            return '';
        }

        $documentSources = $this->hydrateSources($chunks);

        $contextParts = [];

        foreach ($chunks as $index => $chunk) {
            $source = $documentSources[$chunk->document_id ?? null]
                ?? $documentSources[$chunk->knowledge_base_document_id ?? null]
                ?? 'Unknown source';
            $contextParts[] = "[Source {$index}: {$source}]\n{$chunk->content}";
        }

        return implode("\n\n---\n\n", $contextParts);
    }

    /**
     * @param  Collection<int, KnowledgeBaseChunk>  $chunks
     * @return array<string, string>
     */
    private function hydrateSources(Collection $chunks): array
    {
        $documentIds = $chunks->pluck('document_id')->filter()->unique()->all();
        $kbDocIds = $chunks->pluck('knowledge_base_document_id')->filter()->unique()->all();

        $sources = [];

        if (! empty($documentIds)) {
            Document::query()
                ->whereIn('id', $documentIds)
                ->get(['id', 'original_filename', 'name'])
                ->each(function ($doc) use (&$sources) {
                    $sources[$doc->id] = $doc->original_filename ?? $doc->name ?? (string) $doc->id;
                });
        }

        if (! empty($kbDocIds)) {
            KnowledgeBaseDocument::query()
                ->whereIn('id', $kbDocIds)
                ->get(['id', 'source', 'original_filename'])
                ->each(function ($doc) use (&$sources) {
                    $sources[$doc->id] = $doc->original_filename ?? $doc->source ?? (string) $doc->id;
                });
        }

        return $sources;
    }

    /**
     * Perform RAG retrieval: search and build context in one step.
     *
     * @param  array<int, string>  $knowledgeBaseIds
     * @return array{chunks: Collection, context: string, chunk_count: int, knowledge_bases: array<int, array{id: string, name: string}>}
     */
    public function retrieve(
        string $query,
        array $knowledgeBaseIds,
        int $topK = 5,
        float $threshold = 0.7,
    ): array {
        $kbs = empty($knowledgeBaseIds)
            ? collect()
            : KnowledgeBase::query()->whereIn('id', $knowledgeBaseIds)->get();

        // "Retrieve then rerank": when any queried KB opts in and a reranking
        // model is configured, over-fetch candidates by vector similarity and
        // let the reranking model reorder them down to topK. Otherwise the
        // vector order stands.
        $reranker = $this->resolveReranker($kbs);
        $fetchK = $reranker !== null
            ? min($topK * self::RERANK_CANDIDATE_MULTIPLIER, self::RERANK_MAX_CANDIDATES)
            : $topK;

        $chunks = $this->search($query, $knowledgeBaseIds, $fetchK, $threshold);

        $chunks = $reranker !== null && $chunks->count() > 1
            ? $this->rerankChunks($query, $chunks, $reranker, $topK)
            : $chunks->take($topK)->values();

        $context = $this->buildContext($chunks);

        // Pull KB metadata from the application DB — KBs always live there,
        // only the chunks may live elsewhere.
        $kbIds = $chunks->pluck('knowledge_base_id')->unique()->values()->all();
        $knowledgeBases = KnowledgeBase::whereIn('id', $kbIds)
            ->get(['id', 'name'])
            ->map(fn ($kb) => ['id' => $kb->id, 'name' => $kb->name])
            ->values()
            ->all();

        return [
            'chunks' => $chunks,
            'context' => $context,
            'chunk_count' => $chunks->count(),
            'knowledge_bases' => $knowledgeBases,
        ];
    }

    /**
     * Resolve the reranking handler to use for this retrieval, or null when
     * reranking should not run: no KB opted in (config `rerank`), no model is
     * configured under admin AI > Defaults → Reranking, or the configured
     * provider is OpenRouter (which has no rerank endpoint).
     *
     * @param  Collection<int, KnowledgeBase>  $kbs
     * @return array{model: string, driver: string, provider: Lab}|null
     */
    private function resolveReranker(Collection $kbs): ?array
    {
        if ($kbs->isEmpty()) {
            return null;
        }

        $optedIn = $kbs->contains(fn (KnowledgeBase $kb) => (bool) data_get($kb->config, 'rerank', false));
        if (! $optedIn) {
            return null;
        }

        $handler = $this->capabilities->resolve('reranking');
        if ($handler === null || ($handler['driver'] ?? null) === 'openrouter') {
            return null;
        }

        return $handler;
    }

    /**
     * Reorder candidate chunks by relevance to the query using the configured
     * reranking model and keep the best topK. Fails open: on any error the
     * vector-similarity order is preserved so retrieval never breaks.
     *
     * @param  Collection<int, KnowledgeBaseChunk>  $chunks
     * @param  array{model: string, driver: string, provider: Lab}  $handler
     * @return Collection<int, KnowledgeBaseChunk>
     */
    private function rerankChunks(string $query, Collection $chunks, array $handler, int $topK): Collection
    {
        $chunks = $chunks->values();
        $documents = $chunks->map(fn (KnowledgeBaseChunk $c) => (string) $c->content)->all();

        try {
            $response = Reranking::of($documents)
                ->limit($topK)
                ->rerank($query, $handler['provider'], $handler['model']);

            $ordered = collect();
            foreach ($response->results as $result) {
                $chunk = $chunks->get($result->index);
                if ($chunk === null) {
                    continue;
                }
                $chunk->rerank_score = round((float) $result->score, 4);
                $ordered->push($chunk);
            }

            return $ordered->isEmpty()
                ? $chunks->take($topK)->values()
                : $ordered->take($topK)->values();
        } catch (\Throwable $e) {
            Log::warning('RAG reranking failed; falling back to vector order', [
                'model' => $handler['model'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return $chunks->take($topK)->values();
        }
    }

    /**
     * Get similarity score (1 - distance) for a chunk.
     */
    public function getSimilarityScore(KnowledgeBaseChunk $chunk): float
    {
        if (! isset($chunk->distance)) {
            return 0.0;
        }

        return round(1 - $chunk->distance, 4);
    }
}
