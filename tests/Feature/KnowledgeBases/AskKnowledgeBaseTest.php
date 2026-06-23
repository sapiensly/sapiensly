<?php

use App\Enums\Visibility;
use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Models\User;
use App\Services\VectorStoreService;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Embeddings;

beforeEach(function () {
    // Personal context (no org) so the `view` policy passes via ownership,
    // without depending on spatie permissions being seeded in tests.
    $this->user = User::factory()->create();
    $this->kb = KnowledgeBase::factory()->create([
        'user_id' => $this->user->id,
        'visibility' => Visibility::Private,
    ]);
});

it('answers using only this KB and returns retrieval diagnostics + timings', function () {
    // Same fixed vector for query and chunk so cosine distance is 0 (a hit).
    $vector = array_fill(0, 1536, 0.1);
    Embeddings::fake(fn ($prompt) => array_map(fn () => $vector, $prompt->inputs));
    AnonymousAgent::fake(['Shipping takes 3 days.']);

    $doc = Document::create([
        'user_id' => $this->user->id,
        'name' => 'Policy',
        'original_filename' => 'policy.txt',
        'type' => 'txt',
        'file_size' => 100,
        'visibility' => Visibility::Private,
    ]);

    app(VectorStoreService::class)->insertChunks(
        $this->kb,
        [['content' => 'Shipping takes 3 days.', 'index' => 0, 'metadata' => null, 'embedding' => $vector]],
        'text-embedding-3-small',
        documentId: $doc->id,
    );

    $response = $this->actingAs($this->user)
        ->postJson(route('knowledge-bases.ask', $this->kb->id), ['query' => 'how long is shipping?'])
        ->assertOk()
        ->assertJsonStructure([
            'answer',
            'retrieval' => ['chunk_count', 'reranked', 'rerank_model', 'embedding_model', 'stored_embedding_models', 'stale', 'chunks'],
            'timing_ms' => ['retrieval', 'generation', 'total'],
        ]);

    expect($response->json('answer'))->toBe('Shipping takes 3 days.')
        ->and($response->json('retrieval.chunk_count'))->toBe(1)
        ->and($response->json('retrieval.embedding_model'))->toBe('text-embedding-3-small')
        ->and($response->json('retrieval.chunks.0.source'))->toBe('policy.txt');
});

it('requires authentication', function () {
    $this->postJson(route('knowledge-bases.ask', $this->kb->id), ['query' => 'hi'])
        ->assertUnauthorized();
});

it('validates the query is present', function () {
    $this->actingAs($this->user)
        ->postJson(route('knowledge-bases.ask', $this->kb->id), [])
        ->assertStatus(422);
});
