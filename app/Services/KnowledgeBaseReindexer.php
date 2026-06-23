<?php

namespace App\Services;

use App\Enums\EmbeddingStatus;
use App\Jobs\ProcessDocumentForKnowledgeBase;
use App\Jobs\ProcessKnowledgeBaseDocument;
use App\Models\KnowledgeBase;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Keeps a knowledge base's chunks in the same vector space as the queries that
 * will hit them. When a KB's resolved embedding model changes (e.g. the user
 * switches their default embeddings provider), its existing chunks were embedded
 * with the OLD model and live in a different vector space — querying them either
 * errors (dimension mismatch) or silently returns nothing. This service detects
 * that drift and re-embeds the KB's documents with the current model.
 */
class KnowledgeBaseReindexer
{
    public function __construct(private VectorStoreService $vectorStore) {}

    /**
     * Whether the KB's stored chunks were embedded with a model other than the
     * one it now resolves to (so its vector space is stale).
     */
    public function isStale(KnowledgeBase $knowledgeBase): bool
    {
        $storedModels = $this->vectorStore->storedEmbeddingModels($knowledgeBase);
        if ($storedModels === []) {
            return false; // Nothing embedded yet — nothing to invalidate.
        }

        $current = EmbeddingService::forKnowledgeBase($knowledgeBase)->getModel();

        return count($storedModels) > 1 || $storedModels[0] !== $current;
    }

    /**
     * Re-embed the KB's documents only if its chunks are stale. Returns true when
     * a reprocess was triggered.
     */
    public function reprocessIfStale(KnowledgeBase $knowledgeBase): bool
    {
        if (! $this->isStale($knowledgeBase)) {
            return false;
        }

        $this->reprocess($knowledgeBase);

        return true;
    }

    /**
     * Re-dispatch processing for every document in the KB. Each job deletes the
     * document's existing chunks before re-embedding with the current model, so
     * the KB is rebuilt in the new vector space. Returns the number of documents
     * queued.
     */
    public function reprocess(KnowledgeBase $knowledgeBase): int
    {
        $count = 0;

        foreach ($knowledgeBase->attachedDocuments()->get() as $document) {
            $document->knowledgeBases()->updateExistingPivot($knowledgeBase->id, [
                'embedding_status' => EmbeddingStatus::Pending->value,
                'error_message' => null,
                'updated_at' => now(),
            ]);
            ProcessDocumentForKnowledgeBase::dispatch($document, $knowledgeBase);
            $count++;
        }

        // Legacy KnowledgeBaseDocument records (older ingestion path).
        foreach ($knowledgeBase->documents()->get() as $legacyDocument) {
            $legacyDocument->update(['embedding_status' => EmbeddingStatus::Pending->value]);
            ProcessKnowledgeBaseDocument::dispatch($legacyDocument);
            $count++;
        }

        Log::info("Reindexing knowledge base {$knowledgeBase->id}: queued {$count} document(s) for re-embedding");

        return $count;
    }

    /**
     * Reprocess every stale knowledge base the user can act on — used after the
     * user changes their default embeddings provider, which retroactively changes
     * the model every KB without an explicit override resolves to.
     */
    public function reprocessStaleForUser(User $user): int
    {
        $reprocessed = 0;

        KnowledgeBase::forAccountContext($user)->get()->each(function (KnowledgeBase $kb) use (&$reprocessed): void {
            if ($this->reprocessIfStale($kb)) {
                $reprocessed++;
            }
        });

        return $reprocessed;
    }
}
