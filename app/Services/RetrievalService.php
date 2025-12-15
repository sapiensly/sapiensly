<?php

namespace App\Services;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeBaseChunk;
use Illuminate\Support\Collection;
use Pgvector\Laravel\Vector;

class RetrievalService
{
    private EmbeddingService $embeddingService;

    public function __construct(?EmbeddingService $embeddingService = null)
    {
        $this->embeddingService = $embeddingService ?? new EmbeddingService;
    }

    /**
     * Search for relevant chunks across knowledge bases.
     *
     * @param  array<int>  $knowledgeBaseIds
     * @return Collection<KnowledgeBaseChunk>
     */
    public function search(
        string $query,
        array $knowledgeBaseIds,
        int $topK = 5,
        float $threshold = 0.7
    ): Collection {
        if (empty($knowledgeBaseIds)) {
            return collect();
        }

        // Generate embedding for the query
        $queryEmbedding = $this->embeddingService->embed($query);
        $vectorString = '['.implode(',', $queryEmbedding).']';

        // Perform vector similarity search using cosine distance
        // pgvector's <=> operator calculates cosine distance (1 - cosine similarity)
        // So distance = 0 means identical, distance = 2 means opposite
        // We convert threshold (similarity) to distance threshold
        $distanceThreshold = 1 - $threshold;

        $chunks = KnowledgeBaseChunk::query()
            ->whereIn('knowledge_base_id', $knowledgeBaseIds)
            ->whereNotNull('embedding')
            ->whereRaw('embedding <=> ? <= ?', [$vectorString, $distanceThreshold])
            ->selectRaw('*, embedding <=> ? as distance', [$vectorString])
            ->orderByRaw('embedding <=> ? ASC', [$vectorString])
            ->limit($topK)
            ->get();

        return $chunks;
    }

    /**
     * Search using a specific KnowledgeBase's embedding configuration.
     *
     * @return Collection<KnowledgeBaseChunk>
     */
    public function searchForKnowledgeBase(
        string $query,
        KnowledgeBase $knowledgeBase,
        int $topK = 5,
        float $threshold = 0.7
    ): Collection {
        // Use the knowledge base's embedding configuration
        $embeddingService = EmbeddingService::forKnowledgeBase($knowledgeBase);

        $retriever = new self($embeddingService);

        return $retriever->search($query, [$knowledgeBase->id], $topK, $threshold);
    }

    /**
     * Build a context string from retrieved chunks.
     *
     * @param  Collection<KnowledgeBaseChunk>  $chunks
     */
    public function buildContext(Collection $chunks): string
    {
        if ($chunks->isEmpty()) {
            return '';
        }

        $contextParts = [];

        foreach ($chunks as $index => $chunk) {
            $source = $chunk->document?->source ?? 'Unknown source';
            $contextParts[] = "[Source {$index}: {$source}]\n{$chunk->content}";
        }

        return implode("\n\n---\n\n", $contextParts);
    }

    /**
     * Perform RAG retrieval: search and build context in one step.
     *
     * @param  array<int>  $knowledgeBaseIds
     */
    public function retrieve(
        string $query,
        array $knowledgeBaseIds,
        int $topK = 5,
        float $threshold = 0.7
    ): array {
        $chunks = $this->search($query, $knowledgeBaseIds, $topK, $threshold);
        $context = $this->buildContext($chunks);

        return [
            'chunks' => $chunks,
            'context' => $context,
            'chunk_count' => $chunks->count(),
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
