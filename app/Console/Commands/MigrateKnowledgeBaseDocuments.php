<?php

namespace App\Console\Commands;

use App\Enums\DocumentType;
use App\Enums\EmbeddingStatus;
use App\Enums\KnowledgeBaseStatus;
use App\Enums\Visibility;
use App\Models\Document;
use App\Models\KnowledgeBaseChunk;
use App\Models\KnowledgeBaseDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateKnowledgeBaseDocuments extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'documents:migrate-from-knowledge-bases
                            {--dry-run : Run without making changes}
                            {--skip-chunks : Skip chunk migration (faster for testing)}';

    /**
     * The console command description.
     */
    protected $description = 'Migrate KnowledgeBaseDocument records to the new Document model';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $skipChunks = $this->option('skip-chunks');

        if ($dryRun) {
            $this->info('Running in dry-run mode. No changes will be made.');
        }

        $documents = KnowledgeBaseDocument::with(['knowledgeBase.user'])->get();

        if ($documents->isEmpty()) {
            $this->info('No KnowledgeBaseDocuments to migrate.');

            return self::SUCCESS;
        }

        $this->info("Found {$documents->count()} documents to migrate.");

        $bar = $this->output->createProgressBar($documents->count());
        $bar->start();

        $migratedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;

        foreach ($documents as $kbDoc) {
            try {
                // Skip URL type documents as they don't have files
                if ($kbDoc->type === DocumentType::Url) {
                    $skippedCount++;
                    $bar->advance();

                    continue;
                }

                // Check if already migrated (same file_path exists)
                if ($kbDoc->file_path) {
                    $existingDoc = Document::where('file_path', $kbDoc->file_path)->first();
                    if ($existingDoc) {
                        // Already migrated, just ensure it's attached to the KB
                        if (! $dryRun) {
                            $this->attachToKnowledgeBase($existingDoc, $kbDoc);
                        }
                        $skippedCount++;
                        $bar->advance();

                        continue;
                    }
                }

                if (! $dryRun) {
                    DB::transaction(function () use ($kbDoc, $skipChunks, &$migratedCount) {
                        // Create new Document
                        $document = Document::create([
                            'user_id' => $kbDoc->knowledgeBase->user_id,
                            'organization_id' => null,
                            'folder_id' => null,
                            'name' => $this->extractName($kbDoc),
                            'type' => $kbDoc->type,
                            'original_filename' => $kbDoc->original_filename,
                            'file_path' => $kbDoc->file_path,
                            'file_size' => $kbDoc->file_size,
                            'visibility' => Visibility::Private,
                            'metadata' => $kbDoc->metadata,
                        ]);

                        // Attach to knowledge base
                        $this->attachToKnowledgeBase($document, $kbDoc);

                        // Update chunks to reference new document
                        if (! $skipChunks) {
                            KnowledgeBaseChunk::where('knowledge_base_document_id', $kbDoc->id)
                                ->update(['document_id' => $document->id]);
                        }

                        $migratedCount++;
                    });
                } else {
                    $migratedCount++;
                }

                $bar->advance();

            } catch (\Throwable $e) {
                $errorCount++;
                $this->newLine();
                $this->error("Error migrating document {$kbDoc->id}: {$e->getMessage()}");
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Migration complete:');
        $this->info("  - Migrated: {$migratedCount}");
        $this->info("  - Skipped: {$skippedCount}");
        $this->info("  - Errors: {$errorCount}");

        if ($dryRun) {
            $this->warn('This was a dry run. Run without --dry-run to apply changes.');
        }

        return self::SUCCESS;
    }

    /**
     * Extract a name from the KnowledgeBaseDocument.
     */
    private function extractName(KnowledgeBaseDocument $kbDoc): string
    {
        if ($kbDoc->original_filename) {
            // Remove extension
            return pathinfo($kbDoc->original_filename, PATHINFO_FILENAME);
        }

        if ($kbDoc->source) {
            // For URLs, use the last path segment
            $parsed = parse_url($kbDoc->source);
            if (isset($parsed['path'])) {
                $path = trim($parsed['path'], '/');
                if ($path) {
                    return pathinfo($path, PATHINFO_FILENAME) ?: basename($path);
                }
            }

            return 'Document from '.$parsed['host'];
        }

        return 'Document '.$kbDoc->id;
    }

    /**
     * Attach document to knowledge base via pivot.
     */
    private function attachToKnowledgeBase(Document $document, KnowledgeBaseDocument $kbDoc): void
    {
        $knowledgeBase = $kbDoc->knowledgeBase;

        // Check if already attached
        if ($document->knowledgeBases()->where('knowledge_base_id', $knowledgeBase->id)->exists()) {
            return;
        }

        // Map KnowledgeBaseStatus to EmbeddingStatus
        $embeddingStatus = match ($kbDoc->embedding_status) {
            KnowledgeBaseStatus::Ready => EmbeddingStatus::Ready,
            KnowledgeBaseStatus::Processing => EmbeddingStatus::Processing,
            KnowledgeBaseStatus::Failed => EmbeddingStatus::Failed,
            default => EmbeddingStatus::Pending,
        };

        $document->knowledgeBases()->attach($knowledgeBase->id, [
            'embedding_status' => $embeddingStatus->value,
            'error_message' => $kbDoc->error_message,
            'created_at' => $kbDoc->created_at,
            'updated_at' => $kbDoc->updated_at,
        ]);
    }
}
