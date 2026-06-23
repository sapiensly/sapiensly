<?php

use App\Models\AiCatalogModel;
use App\Models\KnowledgeBase;
use App\Models\User;
use App\Services\Ai\AiCapabilities;
use App\Services\Ai\AiPricing;
use App\Services\RetrievalCostEstimator;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Enums\Lab;

beforeEach(function () {
    Cache::flush();
    $this->user = User::factory()->create();

    AiCatalogModel::updateOrCreate(
        ['driver' => 'openai', 'model_id' => 'text-embedding-3-small', 'capability' => 'embeddings'],
        ['label' => 'Embed Small', 'input_price_per_mtok' => 0.02, 'is_enabled' => true],
    );
    AiCatalogModel::updateOrCreate(
        ['driver' => 'cohere', 'model_id' => 'rerank-v3.5', 'capability' => 'rerank'],
        ['label' => 'Cohere Rerank', 'price_per_request' => 0.002, 'is_enabled' => true],
    );
});

it('estimates embedding + rerank cost when a KB opts into reranking', function () {
    $kb = KnowledgeBase::factory()->create([
        'user_id' => $this->user->id,
        'config' => ['rerank' => true],
    ]);

    $caps = Mockery::mock(AiCapabilities::class);
    $caps->shouldReceive('resolve')->with('reranking')->andReturn([
        'model' => 'rerank-v3.5', 'driver' => 'cohere', 'provider' => Lab::Cohere,
    ]);

    $estimate = (new RetrievalCostEstimator(app(AiPricing::class), $caps))
        ->estimate(str_repeat('x', 400), [$kb->id], topK: 5);

    expect($estimate['rerank_cost'])->toBe(0.002)            // 1 search * 0.002
        ->and($estimate['rerank_model'])->toBe('rerank-v3.5')
        ->and($estimate['embedding_cost'])->toBe(0.000002)   // 100 tokens * 0.02 / 1e6
        ->and($estimate['total_cost'])->toBe(0.002002)
        ->and($estimate['currency'])->toBe('USD');
});

it('omits rerank cost when no KB opts in', function () {
    $kb = KnowledgeBase::factory()->create([
        'user_id' => $this->user->id,
        'config' => [],
    ]);

    $caps = Mockery::mock(AiCapabilities::class);
    $caps->shouldNotReceive('resolve');

    $estimate = (new RetrievalCostEstimator(app(AiPricing::class), $caps))
        ->estimate('hello', [$kb->id]);

    expect($estimate['rerank_cost'])->toBe(0.0)
        ->and($estimate['rerank_model'])->toBeNull();
});
