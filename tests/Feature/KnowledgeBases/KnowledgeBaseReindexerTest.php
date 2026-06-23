<?php

use App\Enums\Visibility;
use App\Jobs\ProcessDocumentForKnowledgeBase;
use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Models\Organization;
use App\Models\User;
use App\Services\KnowledgeBaseReindexer;
use App\Services\VectorStoreService;
use Illuminate\Support\Facades\Queue;

function reindexerKb(array $config, ?User $user = null): KnowledgeBase
{
    $org = Organization::create(['name' => 'RX', 'slug' => 'rx-'.uniqid()]);
    $user ??= User::factory()->create(['organization_id' => $org->id]);

    return KnowledgeBase::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->organization_id ?? $org->id,
        'visibility' => Visibility::Organization,
        'config' => $config,
    ]);
}

function seedChunk(KnowledgeBase $kb, string $model, ?string $documentId = null): void
{
    app(VectorStoreService::class)->insertChunks(
        $kb,
        [['content' => 'a', 'index' => 0, 'metadata' => null, 'embedding' => null]],
        $model,
        documentId: $documentId,
    );
}

it('is stale when chunks were embedded with a different model', function () {
    $kb = reindexerKb(['embedding_provider' => 'openai', 'embedding_model' => 'text-embedding-3-large']);
    seedChunk($kb, 'text-embedding-3-small');

    expect(app(KnowledgeBaseReindexer::class)->isStale($kb))->toBeTrue();
});

it('is not stale when chunks match the current model', function () {
    $kb = reindexerKb(['embedding_provider' => 'openai', 'embedding_model' => 'text-embedding-3-large']);
    seedChunk($kb, 'text-embedding-3-large');

    expect(app(KnowledgeBaseReindexer::class)->isStale($kb))->toBeFalse();
});

it('is not stale when there are no chunks yet', function () {
    $kb = reindexerKb([]);

    expect(app(KnowledgeBaseReindexer::class)->isStale($kb))->toBeFalse();
});

it('re-dispatches processing for attached documents when stale', function () {
    Queue::fake();

    $kb = reindexerKb(['embedding_provider' => 'openai', 'embedding_model' => 'text-embedding-3-large']);
    $doc = Document::create([
        'user_id' => $kb->user_id,
        'organization_id' => $kb->organization_id,
        'name' => 'D',
        'original_filename' => 'd.txt',
        'type' => 'txt',
        'file_size' => 1,
        'visibility' => Visibility::Organization,
    ]);
    $doc->knowledgeBases()->attach($kb->id, [
        'embedding_status' => 'ready',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    seedChunk($kb, 'text-embedding-3-small', $doc->id);

    $triggered = app(KnowledgeBaseReindexer::class)->reprocessIfStale($kb);

    expect($triggered)->toBeTrue();
    Queue::assertPushed(ProcessDocumentForKnowledgeBase::class, 1);

    $pivot = $doc->knowledgeBases()->where('knowledge_base_id', $kb->id)->first()->pivot;
    expect($pivot->embedding_status)->toBe('pending');
});

it('reprocesses only the stale knowledge bases for a user', function () {
    Queue::fake();

    $user = User::factory()->create(['organization_id' => Organization::create(['name' => 'O', 'slug' => 'o-'.uniqid()])->id]);

    $stale = reindexerKb(['embedding_provider' => 'openai', 'embedding_model' => 'text-embedding-3-large'], $user);
    seedChunk($stale, 'text-embedding-3-small');

    $fresh = reindexerKb(['embedding_provider' => 'openai', 'embedding_model' => 'text-embedding-3-large'], $user);
    seedChunk($fresh, 'text-embedding-3-large');

    $count = app(KnowledgeBaseReindexer::class)->reprocessStaleForUser($user);

    expect($count)->toBe(1);
});
