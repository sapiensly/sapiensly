<?php

namespace App\Services;

use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeBaseChunk;
use App\Models\KnowledgeBaseDocument;
use Illuminate\Support\Collection;

class RetrievalService
{
    private EmbeddingService $embeddingService;

    private VectorStoreService $vectorStoreService;

    public function __construct(
        ?EmbeddingService $embeddingService = null,
        ?VectorStoreService $vectorStoreService = null,
    ) {
        $this->embeddingService = $embeddingService ?? new EmbeddingService;
        $this->vectorStoreService = $vectorStoreService ?? app(VectorStoreService::class);
    }

    /**
     * Search for relevant chunks across knowledge bases. Routing to the
     * correct database connection per KB is handled by VectorStoreService;
     * this method only concerns itself with generating the query embedding
     * and returning the ordered chunk collection.
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

        $queryEmbedding = $this->embeddingService->embed($query);

        return $this->vectorStoreService->searchSimilar(
            $knowledgeBaseIds,
            $queryEmbedding,
            $topK,
            $threshold,
        );
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

        $retriever = new self($embeddingService, $this->vectorStoreService);

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
        $chunks = $this->search($query, $knowledgeBaseIds, $topK, $threshold);
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
