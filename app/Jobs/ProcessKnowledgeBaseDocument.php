<?php

namespace App\Jobs;

use App\Enums\KnowledgeBaseStatus;
use App\Events\DocumentStatusChanged;
use App\Models\KnowledgeBaseChunk;
use App\Models\KnowledgeBaseDocument;
use App\Services\ChunkingService;
use App\Services\DocumentParserService;
use App\Services\EmbeddingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessKnowledgeBaseDocument implements ShouldQueue
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
        public KnowledgeBaseDocument $document
    ) {
        $this->onQueue('ai');
    }

    /**
     * Execute the job.
     */
    public function handle(
        DocumentParserService $parser,
        ChunkingService $chunker,
    ): void {
        $document = $this->document;
        $knowledgeBase = $document->knowledgeBase;

        Log::info("Processing document: {$document->id} ({$document->source})");

        try {
            // Update status to processing
            $document->update(['embedding_status' => KnowledgeBaseStatus::Processing]);
            $knowledgeBase->update(['status' => KnowledgeBaseStatus::Processing]);

            $this->broadcastStatus($document, $knowledgeBase, KnowledgeBaseStatus::Processing);

            // Parse document
            Log::info("Parsing document: {$document->id}");
            $content = $parser->parse($document);

            if (empty(trim($content))) {
                throw new \RuntimeException('Document content is empty');
            }

            // Store parsed content
            $document->update(['content' => $content]);

            // Chunk content
            Log::info("Chunking document: {$document->id}");
            $chunks = $chunker->chunkForKnowledgeBase($content, $knowledgeBase);

            if (empty($chunks)) {
                throw new \RuntimeException('No chunks generated from document');
            }

            Log::info("Generated {$this->countChunks($chunks)} chunks for document: {$document->id}");

            // Generate embeddings
            $embeddingService = EmbeddingService::forKnowledgeBase($knowledgeBase);
            $chunkTexts = array_column($chunks, 'content');

            Log::info("Generating embeddings for {$this->countChunks($chunks)} chunks");

            // Process in batches of 100 to avoid API limits
            $batchSize = 100;
            $textBatches = array_chunk($chunkTexts, $batchSize);
            $allEmbeddings = [];

            foreach ($textBatches as $batchIndex => $batch) {
                Log::info('Processing embedding batch '.($batchIndex + 1).' of '.count($textBatches));
                $embeddings = $embeddingService->embedBatch($batch);
                $allEmbeddings = array_merge($allEmbeddings, $embeddings);
            }

            // Delete existing chunks for this document
            KnowledgeBaseChunk::where('knowledge_base_document_id', $document->id)->delete();

            // Store chunks with embeddings
            Log::info("Storing chunks for document: {$document->id}");
            $embeddingModel = $embeddingService->getModel();

            DB::transaction(function () use ($document, $knowledgeBase, $chunks, $allEmbeddings, $embeddingModel) {
                foreach ($chunks as $index => $chunk) {
                    KnowledgeBaseChunk::create([
                        'knowledge_base_document_id' => $document->id,
                        'knowledge_base_id' => $knowledgeBase->id,
                        'content' => $chunk['content'],
                        'chunk_index' => $chunk['index'],
                        'metadata' => $chunk['metadata'],
                        'embedding' => $allEmbeddings[$index] ?? null,
                        'embedding_model' => $embeddingModel,
                    ]);
                }
            });

            // Update document status
            $document->update(['embedding_status' => KnowledgeBaseStatus::Ready]);

            // Update knowledge base counts and status
            $this->updateKnowledgeBaseCounts($knowledgeBase);
            $this->updateKnowledgeBaseStatus($knowledgeBase);

            $this->broadcastStatus($document, $knowledgeBase->fresh(), KnowledgeBaseStatus::Ready);

            Log::info("Successfully processed document: {$document->id}");

        } catch (\Throwable $e) {
            Log::error("Failed to process document: {$document->id}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $document->update([
                'embedding_status' => KnowledgeBaseStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);

            // Check if all documents have failed
            $this->updateKnowledgeBaseStatus($knowledgeBase);

            $this->broadcastStatus($document, $knowledgeBase->fresh(), KnowledgeBaseStatus::Failed, $e->getMessage());

            throw $e;
        }
    }

    /**
     * Broadcast document status change via WebSocket.
     */
    private function broadcastStatus(
        KnowledgeBaseDocument $document,
        $knowledgeBase,
        KnowledgeBaseStatus $status,
        ?string $errorMessage = null,
    ): void {
        DocumentStatusChanged::dispatch(
            $knowledgeBase->id,
            $document->id,
            $status->value,
            $errorMessage,
            $knowledgeBase->status->value ?? $knowledgeBase->status,
            $knowledgeBase->document_count,
            $knowledgeBase->chunk_count,
        );
    }

    /**
     * Count chunks in array.
     */
    private function countChunks(array $chunks): int
    {
        return count($chunks);
    }

    /**
     * Update the knowledge base document and chunk counts.
     */
    private function updateKnowledgeBaseCounts($knowledgeBase): void
    {
        $knowledgeBase->update([
            'document_count' => $knowledgeBase->documents()->count(),
            'chunk_count' => $knowledgeBase->chunks()->count(),
        ]);
    }

    /**
     * Update the knowledge base status based on document statuses.
     */
    private function updateKnowledgeBaseStatus($knowledgeBase): void
    {
        $documents = $knowledgeBase->documents;

        if ($documents->isEmpty()) {
            $knowledgeBase->update(['status' => KnowledgeBaseStatus::Pending]);

            return;
        }

        $allReady = $documents->every(fn ($doc) => $doc->embedding_status === KnowledgeBaseStatus::Ready);
        $anyProcessing = $documents->contains(fn ($doc) => $doc->embedding_status === KnowledgeBaseStatus::Processing);
        $allFailed = $documents->every(fn ($doc) => $doc->embedding_status === KnowledgeBaseStatus::Failed);

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
        Log::error("Document processing job failed permanently: {$this->document->id}", [
            'error' => $exception->getMessage(),
        ]);

        $this->document->update([
            'embedding_status' => KnowledgeBaseStatus::Failed,
            'error_message' => $exception->getMessage(),
        ]);
    }
}
