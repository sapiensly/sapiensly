<?php

use App\Enums\DocumentType;
use App\Enums\Visibility;
use App\Models\AiCatalogModel;
use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Models\User;
use App\Services\IngestionCostEstimator;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    $this->user = User::factory()->create();

    // Default embedding model price so embedding cost is computable.
    AiCatalogModel::updateOrCreate(
        ['driver' => 'openai', 'model_id' => 'text-embedding-3-small', 'capability' => 'embeddings'],
        ['label' => 'Embed Small', 'input_price_per_mtok' => 0.02, 'is_enabled' => true],
    );

    $this->kb = KnowledgeBase::factory()->create(['user_id' => $this->user->id]);
    $this->estimator = app(IngestionCostEstimator::class);
});

it('estimates a text document as in-process extraction with no OCR cost', function () {
    $doc = Document::create([
        'user_id' => $this->user->id,
        'name' => 'Notes',
        'original_filename' => 'notes.txt',
        'type' => DocumentType::Txt,
        'file_size' => 4000, // ~1000 tokens at 4 chars/token
        'visibility' => Visibility::Private,
    ]);

    $estimate = $this->estimator->estimateForDocument($doc, $this->kb);

    expect($estimate['method'])->toBe('php')
        ->and($estimate['engine'])->toBeNull()
        ->and($estimate['pages'])->toBe(0)
        ->and($estimate['estimated_tokens'])->toBe(1000)
        ->and($estimate['ocr_cost'])->toBe(0.0)
        // 1000 tokens * 0.02 / 1e6
        ->and($estimate['embedding_cost'])->toBe(0.00002)
        ->and($estimate['total_cost'])->toBe(0.00002)
        ->and($estimate['embedding_model'])->toBe('text-embedding-3-small');
});

it('estimates an inline document from its body length', function () {
    $doc = Document::create([
        'user_id' => $this->user->id,
        'name' => 'Inline',
        'type' => DocumentType::Artifact,
        'body' => str_repeat('a', 4000),
        'visibility' => Visibility::Private,
    ]);

    $estimate = $this->estimator->estimateForDocument($doc, $this->kb);

    expect($estimate['method'])->toBe('php')
        ->and($estimate['estimated_tokens'])->toBe(1000)
        ->and($estimate['ocr_cost'])->toBe(0.0);
});
