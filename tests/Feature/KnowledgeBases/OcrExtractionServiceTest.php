<?php

use App\Models\AiCatalogModel;
use App\Models\User;
use App\Services\Ai\AiPricing;
use App\Services\Ai\AiUsageRecorder;
use App\Services\Ai\OpenRouterClient;
use App\Services\OcrExtractionService;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    $this->user = User::factory()->create();

    AiCatalogModel::updateOrCreate(
        ['driver' => 'openrouter', 'model_id' => 'mistral-ocr', 'capability' => 'ocr'],
        ['label' => 'Mistral OCR', 'price_per_page' => 0.002, 'is_enabled' => true],
    );
});

it('extracts file-parser markdown and meters the per-page OCR cost', function () {
    $openRouter = Mockery::mock(OpenRouterClient::class);
    $openRouter->shouldReceive('isConfiguredFor')->andReturn(true);
    $openRouter->shouldReceive('chat')->once()->andReturn([
        'choices' => [[
            'message' => [
                'annotations' => [
                    ['file' => ['content' => "# Invoice\nTotal: 100"]],
                ],
            ],
        ]],
    ]);

    $recorder = Mockery::mock(AiUsageRecorder::class);
    $recorder->shouldReceive('record')->once();

    $service = new OcrExtractionService($openRouter, app(AiPricing::class), $recorder);

    $result = $service->extract('%PDF-bytes', 'invoice.pdf', 'mistral-ocr', 5, $this->user);

    expect($result['text'])->toContain('Invoice')
        ->and($result['engine'])->toBe('mistral-ocr')
        ->and($result['pages'])->toBe(5)
        ->and($result['cost'])->toBe(0.01); // 5 pages * 0.002
});

it('throws when OpenRouter is not configured', function () {
    $openRouter = Mockery::mock(OpenRouterClient::class);
    $openRouter->shouldReceive('isConfiguredFor')->andReturn(false);

    $service = new OcrExtractionService($openRouter, app(AiPricing::class), Mockery::mock(AiUsageRecorder::class));

    $service->extract('bytes', 'x.pdf', 'mistral-ocr', 3, $this->user);
})->throws(RuntimeException::class);
