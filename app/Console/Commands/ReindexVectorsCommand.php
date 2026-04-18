<?php

namespace App\Console\Commands;

use App\Jobs\ProcessDocumentForKnowledgeBase;
use App\Jobs\ProcessKnowledgeBaseDocument;
use App\Models\KnowledgeBase;
use App\Services\VectorStoreService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Re-embed documents into their knowledge base's currently-resolved vector
 * store. Useful after changing a tenant / global database provider, swapping
 * embedding models, or recovering a KB whose chunks were lost. Existing
 * chunks are deleted first (the processing jobs themselves do this per
 * document), then each document is re-processed asynchronously on the `ai`
 * queue.
 */
#[Signature('vectors:reindex {--kb= : Reindex only this KB id} {--all : Reindex every KB in the app database}')]
#[Description('Re-embed documents into their knowledge base\'s resolved vector store.')]
class ReindexVectorsCommand extends Command
{
    public function handle(VectorStoreService $vectorStoreService): int
    {
        $kbId = $this->option('kb');
        $all = (bool) $this->option('all');

        if (! $kbId && ! $all) {
            $this->error('Provide either --kb=<id> or --all.');

            return self::FAILURE;
        }

        $query = KnowledgeBase::query();
        if ($kbId) {
            $query->where('id', $kbId);
        }

        $kbs = $query->get();

        if ($kbs->isEmpty()) {
            $this->warn($kbId ? "No knowledge base found with id {$kbId}." : 'No knowledge bases to reindex.');

            return self::SUCCESS;
        }

        $totalDocs = 0;
        $totalKbDocs = 0;

        foreach ($kbs as $kb) {
            $this->line("Reindexing KB {$kb->id} — {$kb->name}");

            $attached = $kb->attachedDocuments()->get();
            foreach ($attached as $doc) {
                ProcessDocumentForKnowledgeBase::dispatch($doc, $kb);
                $totalDocs++;
            }

            $legacy = $kb->documents()->get();
            foreach ($legacy as $kbDoc) {
                ProcessKnowledgeBaseDocument::dispatch($kbDoc);
                $totalKbDocs++;
            }

            $this->line(sprintf(
                '  dispatched %d document(s), %d legacy kb-document(s)',
                $attached->count(),
                $legacy->count(),
            ));
        }

        $this->info(sprintf(
            'Dispatched %d document job(s) and %d legacy kb-document job(s) across %d KB(s).',
            $totalDocs,
            $totalKbDocs,
            $kbs->count(),
        ));

        return self::SUCCESS;
    }
}
