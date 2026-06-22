<?php

use App\Ai\Tools\Capabilities\RerankTool;
use App\Models\AiCatalogModel;
use App\Models\AppSetting;
use App\Services\Ai\AiCapabilities;
use App\Services\Ai\AiDefaults;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\Data\RankedDocument;
use Laravel\Ai\Tools\Request;

function seedRerankDefault(): AiCatalogModel
{
    $row = AiCatalogModel::create([
        'driver' => 'cohere',
        'model_id' => 'rerank-test',
        'capability' => 'rerank',
        'label' => 'Rerank Test',
        'is_enabled' => true,
        'sort_order' => 0,
    ]);
    AppSetting::setValue('admin_v2.ai.reranking.primary', $row->id);

    return $row;
}

it('maps each default category to its model capability', function () {
    $d = app(AiDefaults::class);

    expect($d->capabilityFor('image_generation'))->toBe('image')
        ->and($d->capabilityFor('ocr_pdf'))->toBe('vision')
        ->and($d->capabilityFor('reranking'))->toBe('rerank')
        ->and($d->capabilityFor('audio_recognition'))->toBe('transcription')
        ->and($d->capabilityFor('coding'))->toBe('chat');
});

it('falls back to a hard default for chat but not for specialized capabilities', function () {
    $d = app(AiDefaults::class);

    expect($d->model('chat'))->toBe(AiDefaults::HARD_DEFAULT)
        ->and($d->modelOrNull('image_generation'))->toBeNull();

    expect(fn () => $d->model('image_generation'))->toThrow(RuntimeException::class);
});

it('resolves the configured handler model and provider for a capability', function () {
    seedRerankDefault();

    $resolved = app(AiCapabilities::class)->resolve('reranking');

    expect($resolved)->not->toBeNull()
        ->and($resolved['model'])->toBe('rerank-test')
        ->and($resolved['provider'])->toBe(Lab::Cohere);
});

it('lists only configured capabilities as agent tools', function () {
    expect(app(AiCapabilities::class)->configuredTools())->toBe([]);

    seedRerankDefault();

    $tools = app(AiCapabilities::class)->configuredTools();
    expect($tools)->toHaveKey('rerank')
        ->and($tools['rerank']['model'])->toBe('rerank-test');
});

it('reranks documents through the configured model', function () {
    Reranking::fake([
        [
            new RankedDocument(index: 1, document: 'Laravel is a PHP framework.', score: 0.95),
            new RankedDocument(index: 0, document: 'Django is a Python framework.', score: 0.20),
        ],
    ]);
    seedRerankDefault();

    $tool = new RerankTool(app(AiCapabilities::class));
    $out = (string) $tool->handle(new Request([
        'query' => 'PHP frameworks',
        'documents' => ['Django is a Python framework.', 'Laravel is a PHP framework.'],
    ]));

    expect($out)->toContain('rerank-test')
        ->and($out)->toContain('Laravel is a PHP framework.');
});

it('reports a clear error when the capability is not configured', function () {
    $tool = new RerankTool(app(AiCapabilities::class));

    $out = (string) $tool->handle(new Request([
        'query' => 'x',
        'documents' => ['a', 'b'],
    ]));

    expect($out)->toContain('no reranking model is configured');
});
