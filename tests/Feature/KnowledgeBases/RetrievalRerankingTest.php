<?php

use App\Models\KnowledgeBase;
use App\Models\KnowledgeBaseChunk;
use App\Models\User;
use App\Services\Ai\AiCapabilities;
use App\Services\RetrievalService;
use App\Services\VectorStoreService;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\Data\RankedDocument;

/**
 * Covers the "retrieve then rerank" stage in RetrievalService::retrieve(): a KB
 * that opts in (config `rerank`) and a configured reranking model reorder the
 * vector-search candidates; otherwise the vector order stands. Reranking must
 * also fail open so retrieval never breaks.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    Embeddings::fake();
});

function chunkFor(KnowledgeBase $kb, string $content): KnowledgeBaseChunk
{
    return tap(new KnowledgeBaseChunk, fn ($c) => $c->forceFill([
        'content' => $content,
        'knowledge_base_id' => $kb->id,
    ]));
}

it('reranks candidates when the KB opts in and a model is configured', function () {
    $kb = KnowledgeBase::factory()->create([
        'user_id' => $this->user->id,
        'config' => ['rerank' => true],
    ]);

    // Vector order returns the irrelevant chunk first; reranking flips it.
    $candidates = collect([
        chunkFor($kb, 'irrelevant filler'),
        chunkFor($kb, 'the actual answer'),
    ]);

    $vss = Mockery::mock(VectorStoreService::class);
    $vss->shouldReceive('searchSimilar')->once()->andReturn($candidates);

    $caps = Mockery::mock(AiCapabilities::class);
    $caps->shouldReceive('resolve')->with('reranking')->andReturn([
        'model' => 'rerank-v3.5',
        'driver' => 'cohere',
        'provider' => Lab::Cohere,
    ]);

    Reranking::fake([[
        new RankedDocument(index: 1, document: 'the actual answer', score: 0.99),
        new RankedDocument(index: 0, document: 'irrelevant filler', score: 0.10),
    ]]);

    $result = (new RetrievalService(null, $vss, $caps))->retrieve('q', [$kb->id], topK: 2);

    expect($result['chunks']->first()->content)->toBe('the actual answer')
        ->and($result['chunks']->first()->rerank_score)->toBe(0.99);

    Reranking::assertReranked(fn ($prompt) => $prompt->contains('q'));
});

it('does not rerank when no KB opts in', function () {
    $kb = KnowledgeBase::factory()->create([
        'user_id' => $this->user->id,
        'config' => [],
    ]);

    $vss = Mockery::mock(VectorStoreService::class);
    $vss->shouldReceive('searchSimilar')->once()->andReturn(collect([chunkFor($kb, 'a')]));

    $caps = Mockery::mock(AiCapabilities::class);
    $caps->shouldNotReceive('resolve');

    Reranking::fake();

    (new RetrievalService(null, $vss, $caps))->retrieve('q', [$kb->id]);

    Reranking::assertNothingReranked();
});

it('falls back to vector order when reranking errors', function () {
    $kb = KnowledgeBase::factory()->create([
        'user_id' => $this->user->id,
        'config' => ['rerank' => true],
    ]);

    $candidates = collect([
        chunkFor($kb, 'first by vector'),
        chunkFor($kb, 'second by vector'),
    ]);

    $vss = Mockery::mock(VectorStoreService::class);
    $vss->shouldReceive('searchSimilar')->once()->andReturn($candidates);

    $caps = Mockery::mock(AiCapabilities::class);
    $caps->shouldReceive('resolve')->with('reranking')->andReturn([
        'model' => 'rerank-v3.5',
        'driver' => 'cohere',
        'provider' => Lab::Cohere,
    ]);

    Reranking::fake(function () {
        throw new RuntimeException('rerank provider down');
    });

    $result = (new RetrievalService(null, $vss, $caps))->retrieve('q', [$kb->id], topK: 2);

    expect($result['chunks']->pluck('content')->all())->toBe(['first by vector', 'second by vector']);
});

it('skips reranking for OpenRouter (no rerank endpoint)', function () {
    $kb = KnowledgeBase::factory()->create([
        'user_id' => $this->user->id,
        'config' => ['rerank' => true],
    ]);

    $vss = Mockery::mock(VectorStoreService::class);
    $vss->shouldReceive('searchSimilar')->once()->andReturn(collect([chunkFor($kb, 'a'), chunkFor($kb, 'b')]));

    $caps = Mockery::mock(AiCapabilities::class);
    $caps->shouldReceive('resolve')->with('reranking')->andReturn([
        'model' => 'some-model',
        'driver' => 'openrouter',
        'provider' => Lab::OpenAI,
    ]);

    Reranking::fake();

    (new RetrievalService(null, $vss, $caps))->retrieve('q', [$kb->id]);

    Reranking::assertNothingReranked();
});
