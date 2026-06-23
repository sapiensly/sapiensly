<?php

use App\Enums\DocumentType;
use App\Enums\Visibility;
use App\Models\AiCatalogModel;
use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Models\User;
use App\Services\DocumentService;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Embeddings;

beforeEach(function () {
    Cache::flush();
    $this->user = User::factory()->create();

    AiCatalogModel::updateOrCreate(
        ['driver' => 'openai', 'model_id' => 'text-embedding-3-small', 'capability' => 'embeddings'],
        ['label' => 'Embed Small', 'input_price_per_mtok' => 0.02, 'is_enabled' => true],
    );

    $this->kb = KnowledgeBase::factory()->create(['user_id' => $this->user->id]);
});

it('returns a JSON ingestion cost estimate for a document', function () {
    $doc = Document::create([
        'user_id' => $this->user->id,
        'name' => 'Notes',
        'original_filename' => 'notes.txt',
        'type' => DocumentType::Txt,
        'file_size' => 4000,
        'visibility' => Visibility::Private,
    ]);

    $this->actingAs($this->user)
        ->getJson(route('knowledge-bases.documents.cost-estimate', [
            'knowledge_base' => $this->kb->id,
            'document' => $doc->id,
        ]))
        ->assertOk()
        ->assertJson([
            'method' => 'php',
            'engine' => null,
            'currency' => 'USD',
            'estimated' => true,
        ]);
});

it('records the actual ingestion cost on the pivot after processing', function () {
    Embeddings::fake();

    // Inline document → in-process extraction, no OCR, embeddings priced.
    $doc = Document::create([
        'user_id' => $this->user->id,
        'name' => 'Inline',
        'type' => DocumentType::Txt,
        'body' => str_repeat('lorem ipsum dolor sit amet ', 50),
        'visibility' => Visibility::Private,
    ]);

    app(DocumentService::class)->attachToKnowledgeBase($doc, $this->kb);

    $pivot = $doc->knowledgeBases()
        ->where('knowledge_base_id', $this->kb->id)
        ->first()->pivot;

    expect($pivot->embedding_status)->toBe('ready')
        ->and($pivot->extraction_method)->toBe('php')
        ->and((float) $pivot->ingestion_cost)->toBeGreaterThan(0.0);
});
