<?php

use App\Models\AiCatalogModel;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Image;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\Data\RankedDocument;

function pgUser(): User
{
    return User::factory()->create();
}

function seedModel(string $capability, string $driver, string $modelId): AiCatalogModel
{
    return AiCatalogModel::firstOrCreate(
        ['driver' => $driver, 'model_id' => $modelId, 'capability' => $capability],
        ['label' => $modelId, 'is_enabled' => true, 'sort_order' => 0],
    );
}

function setDefault(string $category, AiCatalogModel $model): void
{
    AppSetting::setValue("admin_v2.ai.{$category}.primary", $model->id);
}

test('index renders every capability and the per-capability model picker', function () {
    $this->actingAs(pgUser())
        ->get('/playground')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('playground/Index')
            ->has('capabilities', 10)
            ->has('modelsByCapability.chat')
            ->has('modelsByCapability.rerank'));
});

test('text run uses the chat hard default and returns the model reply', function () {
    Ai::fakeAgent(AnonymousAgent::class, ['Hello from the text model.']);

    $this->actingAs(pgUser())
        ->post('/playground/run', ['capability' => 'text', 'prompt' => 'Hi'])
        ->assertOk()
        ->assertJson(['ok' => true])
        ->assertJsonPath('text', 'Hello from the text model.');
});

test('embeddings run returns the vector dimensions', function () {
    $model = seedModel('embeddings', 'openai', 'text-embedding-3-small');
    setDefault('embeddings', $model);
    Embeddings::fake([[array_fill(0, 8, 0.1)]]);

    $this->actingAs(pgUser())
        ->post('/playground/run', ['capability' => 'embeddings', 'text' => 'napa valley'])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('dimensions', 8);
});

test('reranking run returns documents ordered best-first', function () {
    $model = seedModel('rerank', 'cohere', 'rerank-v3.5');
    setDefault('reranking', $model);
    Reranking::fake([
        [
            new RankedDocument(index: 1, document: 'Laravel is PHP.', score: 0.9),
            new RankedDocument(index: 0, document: 'Django is Python.', score: 0.2),
        ],
    ]);

    $this->actingAs(pgUser())
        ->post('/playground/run', [
            'capability' => 'reranking',
            'query' => 'PHP frameworks',
            'documents' => ['Django is Python.', 'Laravel is PHP.'],
        ])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('ranked.0.document', 'Laravel is PHP.');
});

test('image generation run returns a base64 data URL', function () {
    $model = seedModel('image', 'openai', 'gpt-image-1');
    setDefault('image_generation', $model);
    Image::fake([base64_encode('PNGDATA')]);

    $res = $this->actingAs(pgUser())
        ->post('/playground/run', ['capability' => 'image_generation', 'prompt' => 'a cat'])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->json();

    expect($res['image'])->toStartWith('data:image/png;base64,');
});

test('a model override of the wrong capability is rejected', function () {
    $embed = seedModel('embeddings', 'openai', 'text-embedding-3-small');

    $this->actingAs(pgUser())
        ->post('/playground/run', [
            'capability' => 'text',
            'model_id' => $embed->id,
            'prompt' => 'Hi',
        ])
        ->assertStatus(422)
        ->assertJsonPath('ok', false);
});

test('an unconfigured capability returns a clear error', function () {
    // No reranking default set and no hard default exists for it.
    $this->actingAs(pgUser())
        ->post('/playground/run', [
            'capability' => 'reranking',
            'query' => 'x',
            'documents' => ['a'],
        ])
        ->assertStatus(422)
        ->assertJsonPath('ok', false);
});

test('OCR-PDF via OpenRouter sends the file-parser plugin with the configured engine', function () {
    config(['ai.providers.openrouter.key' => 'sk-or-test']);
    $model = seedModel('vision', 'openrouter', 'mistralai/mistral-ocr');
    setDefault('ocr_pdf', $model);
    AppSetting::setValue('admin_v2.ai.ocr_pdf.engine', 'cloudflare-ai');

    // The parsed PDF text comes back in the file-parser annotations, even when
    // the model's own reply is a refusal.
    Http::fake([
        'openrouter.ai/*' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => "I can't access external files.",
                    'annotations' => [[
                        'type' => 'file',
                        'file' => [
                            'name' => 'doc.pdf',
                            'content' => [['type' => 'text', 'text' => 'Extracted text body']],
                        ],
                    ]],
                ],
            ]],
        ]),
    ]);

    $this->actingAs(pgUser())
        ->post('/playground/run', [
            'capability' => 'ocr_pdf',
            'file' => UploadedFile::fake()->create('doc.pdf', 12, 'application/pdf'),
        ])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('text', 'Extracted text body');

    Http::assertSent(function ($req) {
        $plugin = $req['plugins'][0] ?? [];
        $fileBlock = collect($req['messages'][0]['content'])->firstWhere('type', 'file');

        return ($plugin['id'] ?? null) === 'file-parser'
            && ($plugin['pdf']['engine'] ?? null) === 'cloudflare-ai'
            && isset($fileBlock['file']['file_data']);
    });
});

test('OCR-PDF via OpenRouter surfaces a clear message when the model returns nothing', function () {
    config(['ai.providers.openrouter.key' => 'sk-or-test']);
    $model = seedModel('vision', 'openrouter', 'mistralai/mistral-nemo');
    setDefault('ocr_pdf', $model);

    Http::fake([
        'openrouter.ai/*' => Http::response([
            'choices' => [['message' => ['content' => ''], 'finish_reason' => 'stop']],
        ]),
    ]);

    $this->actingAs(pgUser())
        ->post('/playground/run', [
            'capability' => 'ocr_pdf',
            'file' => UploadedFile::fake()->create('doc.pdf', 12, 'application/pdf'),
        ])
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJson(fn ($json) => $json->where('error', fn ($e) => str_contains($e, 'No text was extracted'))->etc());
});

test('image generation routes through OpenRouter when the model is an OpenRouter model', function () {
    config(['ai.providers.openrouter.key' => 'sk-or-test']);
    $model = seedModel('image', 'openrouter', 'google/gemini-2.5-flash-image');
    setDefault('image_generation', $model);

    Http::fake([
        'openrouter.ai/*' => Http::response([
            'choices' => [['message' => ['images' => [['image_url' => ['url' => 'data:image/png;base64,QUJD']]]]]],
        ]),
    ]);

    $this->actingAs(pgUser())
        ->post('/playground/run', ['capability' => 'image_generation', 'prompt' => 'a cat'])
        ->assertOk()
        ->assertJsonPath('image', 'data:image/png;base64,QUJD');

    Http::assertSent(fn ($req) => str_contains($req->url(), '/chat/completions')
        && $req['modalities'] === ['image', 'text']);
});
