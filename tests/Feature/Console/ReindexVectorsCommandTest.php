<?php

use App\Enums\EmbeddingStatus;
use App\Enums\Visibility;
use App\Jobs\ProcessDocumentForKnowledgeBase;
use App\Jobs\ProcessKnowledgeBaseDocument;
use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeBaseDocument;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

function seedKbWithDocuments(): array
{
    $org = Organization::create(['name' => 'R', 'slug' => 'reindex-'.uniqid()]);
    $user = User::factory()->create(['organization_id' => $org->id]);

    $kb = KnowledgeBase::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'visibility' => Visibility::Organization,
    ]);

    $doc = Document::create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'D',
        'original_filename' => 'd.txt',
        'type' => 'txt',
        'file_size' => 1,
        'visibility' => Visibility::Organization,
    ]);

    DB::table('document_knowledge_base')->insert([
        'knowledge_base_id' => $kb->id,
        'document_id' => $doc->id,
        'embedding_status' => EmbeddingStatus::Ready->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $kbDoc = KnowledgeBaseDocument::create([
        'knowledge_base_id' => $kb->id,
        'type' => 'txt',
        'source' => 'legacy.txt',
    ]);

    return [$kb, $doc, $kbDoc];
}

test('vectors:reindex without --kb or --all exits with an error', function () {
    $this->artisan('vectors:reindex')
        ->expectsOutputToContain('Provide either --kb=<id> or --all.')
        ->assertFailed();
});

test('vectors:reindex --kb dispatches jobs for that KB only', function () {
    Queue::fake();

    [$kbA, $docA, $kbDocA] = seedKbWithDocuments();
    [$kbB] = seedKbWithDocuments();

    $this->artisan('vectors:reindex', ['--kb' => $kbA->id])
        ->assertSuccessful();

    Queue::assertPushed(ProcessDocumentForKnowledgeBase::class, 1);
    Queue::assertPushed(ProcessKnowledgeBaseDocument::class, 1);

    Queue::assertPushed(
        ProcessDocumentForKnowledgeBase::class,
        fn ($job) => $job->document->id === $docA->id && $job->knowledgeBase->id === $kbA->id,
    );
    Queue::assertPushed(
        ProcessKnowledgeBaseDocument::class,
        fn ($job) => $job->document->id === $kbDocA->id,
    );
});

test('vectors:reindex --all dispatches jobs for every KB', function () {
    Queue::fake();

    seedKbWithDocuments();
    seedKbWithDocuments();

    $this->artisan('vectors:reindex', ['--all' => true])
        ->assertSuccessful();

    Queue::assertPushed(ProcessDocumentForKnowledgeBase::class, 2);
    Queue::assertPushed(ProcessKnowledgeBaseDocument::class, 2);
});

test('vectors:reindex --kb with an unknown id is a no-op but still succeeds', function () {
    Queue::fake();

    $this->artisan('vectors:reindex', ['--kb' => 'kb_ghost'])
        ->expectsOutputToContain('No knowledge base found with id kb_ghost.')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

test('vectors:reindex --all with zero KBs succeeds silently', function () {
    Queue::fake();

    $this->artisan('vectors:reindex', ['--all' => true])
        ->expectsOutputToContain('No knowledge bases to reindex.')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});
