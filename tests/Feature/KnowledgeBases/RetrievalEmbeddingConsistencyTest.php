<?php

use App\Models\KnowledgeBase;
use App\Models\User;
use App\Services\RetrievalService;
use App\Services\VectorStoreService;
use Illuminate\Support\Collection;
use Laravel\Ai\Embeddings;

/**
 * Regression for the KB-freshness bug: the agent couldn't see documents added to
 * a knowledge base after its embedding provider was configured/changed. Cause —
 * ingestion embeds chunks with the KB's embedding config
 * (EmbeddingService::forKnowledgeBase) but retrieval embedded the QUERY with the
 * global-default model, so query and chunks lived in different vector spaces and
 * nothing matched. Retrieval must embed the query with each KB's own config.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
});

/**
 * Capture the embedding model used for each generate() call, and return a
 * correctly-sized dummy vector so EmbeddingService::embed() works.
 */
function captureEmbeddingModels(array &$models): void
{
    Embeddings::fake(function ($prompt) use (&$models) {
        $models[] = $prompt->model;

        return [array_fill(0, $prompt->dimensions, 0.01)];
    });
}

it('embeds the query with the knowledge base\'s own embedding model', function () {
    $kb = KnowledgeBase::factory()->create([
        'user_id' => $this->user->id,
        'config' => ['embedding_provider' => 'openai', 'embedding_model' => 'text-embedding-3-large'],
    ]);

    $models = [];
    captureEmbeddingModels($models);

    // Isolate the vector layer — we only care which model embedded the query.
    $vss = Mockery::mock(VectorStoreService::class);
    $vss->shouldReceive('searchSimilar')->once()->andReturn(new Collection);

    (new RetrievalService(null, $vss))->search('how do refunds work?', [$kb->id]);

    // The query used the KB's model, NOT the global default (text-embedding-3-small).
    expect($models)->toContain('text-embedding-3-large')
        ->and($models)->not->toContain('text-embedding-3-small');
});

it('falls back to the default model when the KB declares none', function () {
    $kb = KnowledgeBase::factory()->create([
        'user_id' => $this->user->id,
        'config' => ['chunk_size' => 1000],
    ]);

    $models = [];
    captureEmbeddingModels($models);

    $vss = Mockery::mock(VectorStoreService::class);
    $vss->shouldReceive('searchSimilar')->once()->andReturn(new Collection);

    (new RetrievalService(null, $vss))->search('hi', [$kb->id]);

    expect($models)->toContain('text-embedding-3-small');
});

it('embeds the query once per distinct embedding config across KBs', function () {
    $kbLarge = KnowledgeBase::factory()->create([
        'user_id' => $this->user->id,
        'config' => ['embedding_provider' => 'openai', 'embedding_model' => 'text-embedding-3-large'],
    ]);
    $kbSmall = KnowledgeBase::factory()->create([
        'user_id' => $this->user->id,
        'config' => ['embedding_provider' => 'openai', 'embedding_model' => 'text-embedding-3-small'],
    ]);

    $models = [];
    captureEmbeddingModels($models);

    $vss = Mockery::mock(VectorStoreService::class);
    // One searchSimilar call per embedding-config group.
    $vss->shouldReceive('searchSimilar')->twice()->andReturn(new Collection);

    (new RetrievalService(null, $vss))->search('q', [$kbLarge->id, $kbSmall->id]);

    expect($models)->toContain('text-embedding-3-large')
        ->and($models)->toContain('text-embedding-3-small');
});
