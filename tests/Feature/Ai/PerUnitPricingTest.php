<?php

use App\Models\AiCatalogModel;
use App\Services\Ai\AiPricing;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();

    AiCatalogModel::updateOrCreate(
        ['driver' => 'openrouter', 'model_id' => 'mistral-ocr', 'capability' => 'ocr'],
        ['label' => 'Mistral OCR', 'price_per_page' => 0.002, 'is_enabled' => true],
    );
    AiCatalogModel::updateOrCreate(
        ['driver' => 'cohere', 'model_id' => 'rerank-v3.5', 'capability' => 'rerank'],
        ['label' => 'Cohere Rerank', 'price_per_request' => 0.002, 'is_enabled' => true],
    );
    AiCatalogModel::updateOrCreate(
        ['driver' => 'jina', 'model_id' => 'test-token-reranker', 'capability' => 'rerank'],
        ['label' => 'Token Rerank', 'input_price_per_mtok' => 0.02, 'is_enabled' => true],
    );

    $this->pricing = app(AiPricing::class);
});

it('costs OCR per page', function () {
    expect($this->pricing->costForPages('mistral-ocr', 10))->toBe(0.02)
        ->and($this->pricing->costForPages('mistral-ocr', 0))->toBe(0.0)
        ->and($this->pricing->costForPages('unknown-engine', 10))->toBe(0.0);
});

it('costs per-request rerankers per search, ignoring token volume', function () {
    expect($this->pricing->costForRerank('rerank-v3.5', 2, 999_999))->toBe(0.004);
});

it('costs token-priced rerankers on input tokens', function () {
    expect($this->pricing->costForRerank('test-token-reranker', 1, 1_000_000))->toBe(0.02);
});

it('exposes per-unit prices and null for unknown models', function () {
    expect($this->pricing->pricePerPage('mistral-ocr'))->toBe(0.002)
        ->and($this->pricing->pricePerRequest('rerank-v3.5'))->toBe(0.002)
        ->and($this->pricing->pricePerPage('rerank-v3.5'))->toBeNull();
});
