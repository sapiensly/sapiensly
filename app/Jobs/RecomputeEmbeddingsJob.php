<?php

namespace App\Jobs;

use App\Models\KnowledgeBase;
use App\Services\VectorStoreService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Reindexes a single Knowledge Base with a new embedding model. Fired from
 * AdminV2AiController when the global embedding model changes. Strategy:
 * drop every chunk the KB currently owns, then re-dispatch the existing
 * ProcessDocumentForKnowledgeBase job per attached document so the current
 * chunking + embedding pipeline runs end-to-end with the new model.
 *
 * Kept intentionally thin so we reuse `ProcessDocumentForKnowledgeBase`
 * (which already knows how to pick up whichever embedding model is
 * configured at runtime).
 */
class RecomputeEmbeddingsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public string $knowledgeBaseId,
        /**
         * The new embedding model id we're migrating to. Stored on the job
         * for traceability; the downstream processing job resolves the
         * model from configuration at run time.
         */
        public string $newEmbeddingModelId,
    ) {
        $this->onQueue('ai');
    }

    public function handle(VectorStoreService $vectorStore): void
    {
        $kb = KnowledgeBase::query()->find($this->knowledgeBaseId);
        if ($kb === null) {
            Log::channel('daily')->warning('recompute_embeddings.kb_missing', [
                'knowledge_base_id' => $this->knowledgeBaseId,
            ]);

            return;
        }

        Log::channel('daily')->info('recompute_embeddings.start', [
            'knowledge_base_id' => $kb->id,
            'new_model' => $this->newEmbeddingModelId,
        ]);

        // Wipe everything the KB stores in the vector column so the next
        // chunking pass writes fresh vectors.
        $vectorStore->deleteAllForKnowledgeBase($kb);

        // Each attached document re-runs through the production ingest
        // pipeline; that job handles chunking, embedding, and storage.
        foreach ($kb->attachedDocuments as $document) {
            ProcessDocumentForKnowledgeBase::dispatch($document, $kb)->onQueue('ai');
        }

        Log::channel('daily')->info('recompute_embeddings.dispatched', [
            'knowledge_base_id' => $kb->id,
            'document_count' => $kb->attachedDocuments()->count(),
        ]);
    }
}
