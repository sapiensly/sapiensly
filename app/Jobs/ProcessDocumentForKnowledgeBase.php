<?php

namespace App\Jobs;

use App\Enums\EmbeddingStatus;
use App\Enums\KnowledgeBaseStatus;
use App\Events\DocumentStatusChanged;
use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Services\ChunkingService;
use App\Services\CloudProviderService;
use App\Services\DocumentParserService;
use App\Services\DocumentService;
use App\Services\EmbeddingService;
use App\Services\VectorStoreService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessDocumentForKnowledgeBase implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Document $document,
        public KnowledgeBase $knowledgeBase
    ) {
        $this->onQueue('ai');
    }

    /**
     * Execute the job.
     */
    public function handle(
        DocumentParserService $parser,
        ChunkingService $chunker,
        DocumentService $documentService,
        CloudProviderService $cloudProviderService,
        VectorStoreService $vectorStoreService,
    ): void {
        $document = $this->document;
        $knowledgeBase = $this->knowledgeBase;

        Log::info("Processing document for KB: {$document->id} ({$document->name}) -> KB: {$knowledgeBase->id}");

        try {
            // Update pivot status to processing
            $this->updatePivotStatus(EmbeddingStatus::Processing);
            $knowledgeBase->update(['status' => KnowledgeBaseStatus::Processing]);

            DocumentStatusChanged::dispatch(
                $knowledgeBase->id,
                $document->id,
                EmbeddingStatus::Processing->value,
                null,
                KnowledgeBaseStatus::Processing->value,
            );

            // Parse document content from the resolved tenant storage disk
            Log::info("Parsing document: {$document->id}");
            $content = $this->parseDocument($document, $parser, $cloudProviderService);

            if (empty(trim($content))) {
                throw new \RuntimeException('Document content is empty');
            }

            // Chunk content
            Log::info("Chunking document: {$document->id}");
            $chunks = $chunker->chunkForKnowledgeBase($content, $knowledgeBase);

            if (empty($chunks)) {
                throw new \RuntimeException('No chunks generated from document');
            }

            Log::info('Generated '.count($chunks)." chunks for document: {$document->id}");

            // Generate embeddings
            $embeddingService = EmbeddingService::forKnowledgeBase($knowledgeBase);
            $chunkTexts = array_column($chunks, 'content');

            Log::info('Generating embeddings for '.count($chunks).' chunks');

            // Process in batches of 100 to avoid API limits
            $batchSize = 100;
            $textBatches = array_chunk($chunkTexts, $batchSize);
            $allEmbeddings = [];

            foreach ($textBatches as $batchIndex => $batch) {
                Log::info('Processing embedding batch '.($batchIndex + 1).' of '.count($textBatches));
                $embeddings = $embeddingService->embedBatch($batch);
                $allEmbeddings = array_merge($allEmbeddings, $embeddings);
            }

            // Delete existing chunks for this document in this KB via the
            // vector-store service so the write routes to whichever database
            // the knowledge base resolves to.
            $vectorStoreService->deleteForDocumentInKnowledgeBase($knowledgeBase, $document->id);

            // Store chunks with embeddings
            Log::info("Storing chunks for document: {$document->id}");
            $embeddingModel = $embeddingService->getModel();

            $chunksWithEmbeddings = array_map(
                fn (int $index, array $chunk) => $chunk + ['embedding' => $allEmbeddings[$index] ?? null],
                array_keys($chunks),
                $chunks,
            );

            $vectorStoreService->insertChunks(
                $knowledgeBase,
                $chunksWithEmbeddings,
                $embeddingModel,
                documentId: $document->id,
            );

            // Update pivot status to ready
            $this->updatePivotStatus(EmbeddingStatus::Ready);

            // Update knowledge base counts and status
            $this->updateKnowledgeBaseCounts($knowledgeBase, $vectorStoreService);
            $this->updateKnowledgeBaseStatus($knowledgeBase);

            $knowledgeBase->refresh();
            DocumentStatusChanged::dispatch(
                $knowledgeBase->id,
                $document->id,
                EmbeddingStatus::Ready->value,
                null,
                $knowledgeBase->status->value ?? $knowledgeBase->status,
                $knowledgeBase->document_count,
                $knowledgeBase->chunk_count,
            );

            Log::info("Successfully processed document for KB: {$document->id}");

        } catch (\Throwable $e) {
            Log::error("Failed to process document for KB: {$document->id}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->updatePivotStatus(EmbeddingStatus::Failed, $e->getMessage());

            // Check if all documents have failed
            $this->updateKnowledgeBaseStatus($knowledgeBase);

            $knowledgeBase->refresh();
            DocumentStatusChanged::dispatch(
                $knowledgeBase->id,
                $document->id,
                EmbeddingStatus::Failed->value,
                $e->getMessage(),
                $knowledgeBase->status->value ?? $knowledgeBase->status,
            );

            throw $e;
        }
    }

    /**
     * Parse document content from the storage disk resolved for the document's tenant.
     * Inline-authored documents short-circuit: their content lives on the model
     * directly so we hand it to the parser without any disk round-trip.
     */
    private function parseDocument(
        Document $document,
        DocumentParserService $parser,
        CloudProviderService $cloudProviderService,
    ): string {
        if ($document->isInline()) {
            return (string) $document->body;
        }

        if (! $document->file_path) {
            throw new \RuntimeException('Document has no file path');
        }

        $storage = $cloudProviderService->diskForOrganizationOrFallback($document->organization_id);

        if (! $storage->exists($document->file_path)) {
            throw new \RuntimeException("Document file not found: {$document->file_path}");
        }

        // Download to temp file for parsing
        $extension = $document->type->extension();
        $tempFile = tempnam(sys_get_temp_dir(), 'doc_').'.'.$extension;

        try {
            $content = $storage->get($document->file_path);
            file_put_contents($tempFile, $content);

            return $parser->parseFile($tempFile, $document->type);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * Update the pivot table status.
     */
    private function updatePivotStatus(EmbeddingStatus $status, ?string $errorMessage = null): void
    {
        $this->document->knowledgeBases()->updateExistingPivot($this->knowledgeBase->id, [
            'embedding_status' => $status->value,
            'error_message' => $errorMessage,
            'updated_at' => now(),
        ]);
    }

    /**
     * Update the knowledge base document and chunk counts.
     */
    private function updateKnowledgeBaseCounts(KnowledgeBase $knowledgeBase, VectorStoreService $vectorStoreService): void
    {
        // Count both legacy documents and new attached documents
        $legacyCount = $knowledgeBase->documents()->count();
        $newCount = $knowledgeBase->attachedDocuments()->count();

        $knowledgeBase->update([
            'document_count' => $legacyCount + $newCount,
            'chunk_count' => $vectorStoreService->chunkCount($knowledgeBase),
        ]);
    }

    /**
     * Update the knowledge base status based on document statuses.
     */
    private function updateKnowledgeBaseStatus(KnowledgeBase $knowledgeBase): void
    {
        // Check both legacy and new documents
        $legacyDocs = $knowledgeBase->documents;
        $newDocs = $knowledgeBase->attachedDocuments;

        if ($legacyDocs->isEmpty() && $newDocs->isEmpty()) {
            $knowledgeBase->update(['status' => KnowledgeBaseStatus::Pending]);

            return;
        }

        // Collect all statuses
        $statuses = collect();

        foreach ($legacyDocs as $doc) {
            $statuses->push($doc->embedding_status);
        }

        foreach ($newDocs as $doc) {
            $statuses->push(EmbeddingStatus::tryFrom($doc->pivot->embedding_status) ?? EmbeddingStatus::Pending);
        }

        $allReady = $statuses->every(fn ($s) => $s === KnowledgeBaseStatus::Ready || $s === EmbeddingStatus::Ready);
        $anyProcessing = $statuses->contains(fn ($s) => $s === KnowledgeBaseStatus::Processing || $s === EmbeddingStatus::Processing);
        $allFailed = $statuses->every(fn ($s) => $s === KnowledgeBaseStatus::Failed || $s === EmbeddingStatus::Failed);

        if ($allReady) {
            $knowledgeBase->update(['status' => KnowledgeBaseStatus::Ready]);
        } elseif ($allFailed) {
            $knowledgeBase->update(['status' => KnowledgeBaseStatus::Failed]);
        } elseif ($anyProcessing) {
            $knowledgeBase->update(['status' => KnowledgeBaseStatus::Processing]);
        } else {
            // Mix of ready, failed, and pending - consider it partially ready
            $knowledgeBase->update(['status' => KnowledgeBaseStatus::Ready]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Document processing job failed permanently: {$this->document->id} -> KB: {$this->knowledgeBase->id}", [
            'error' => $exception->getMessage(),
        ]);

        $this->updatePivotStatus(EmbeddingStatus::Failed, $exception->getMessage());
    }
}
