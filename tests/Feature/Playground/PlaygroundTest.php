<?php

use App\Jobs\ExecutePlaygroundRun;
use App\Models\AiCatalogModel;
use App\Models\AppSetting;
use App\Models\PlaygroundRun;
use App\Models\User;
use App\Services\Ai\PlaygroundRunner;
use App\Services\AiProviderService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Ai;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Image;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\RankedDocument;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;

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
            ->has('capabilities', 9)
            ->has('modelsByCapability.chat')
            ->has('modelsByCapability.rerank'));
});

test('the model picker only offers models whose driver has a usable key', function () {
    seedModel('chat', 'openai', 'gpt-keyless');
    seedModel('chat', 'anthropic', 'claude-keyed');
    config(['ai.providers.openai.key' => '', 'ai.providers.anthropic.key' => 'sk-ant-test']);

    $this->actingAs(pgUser())
        ->get('/playground')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('modelsByCapability.chat', fn ($models) => collect($models)->pluck('driver')->doesntContain('openai')
                && collect($models)->pluck('driver')->contains('anthropic')));
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

test('a run reports duration, token usage and cost', function () {
    config(['ai.providers.openrouter.key' => 'sk-or-test']);
    $model = AiCatalogModel::create([
        'driver' => 'openrouter',
        'model_id' => 'mistralai/mistral-ocr',
        'capability' => 'vision',
        'label' => 'OCR',
        'is_enabled' => true,
        'sort_order' => 0,
        'input_price_per_mtok' => 1.0,
        'output_price_per_mtok' => 2.0,
    ]);
    setDefault('ocr_pdf', $model);

    Http::fake([
        'openrouter.ai/*' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => 'x',
                    'annotations' => [[
                        'type' => 'file',
                        'file' => ['content' => [['type' => 'text', 'text' => 'Parsed text']]],
                    ]],
                ],
            ]],
            'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 500, 'total_tokens' => 1500],
        ]),
    ]);

    $res = $this->actingAs(pgUser())
        ->post('/playground/run', [
            'capability' => 'ocr_pdf',
            'file' => UploadedFile::fake()->create('doc.pdf', 12, 'application/pdf'),
        ])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('usage.total_tokens', 1500)
        // cost = 1000/1e6*1 + 500/1e6*2 = 0.002
        ->assertJsonPath('usage.cost', 0.002)
        ->json();

    expect($res)->toHaveKey('duration_ms')
        ->and($res['duration_ms'])->toBeGreaterThanOrEqual(0);
});

test('OCR-PDF inlines extracted figures into the markdown instead of broken refs', function () {
    config(['ai.providers.openrouter.key' => 'sk-or-test']);
    $model = seedModel('vision', 'openrouter', 'mistralai/mistral-ocr');
    setDefault('ocr_pdf', $model);

    Http::fake([
        'openrouter.ai/*' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => '',
                    'annotations' => [[
                        'type' => 'file',
                        'file' => [
                            'content' => [
                                ['type' => 'text', 'text' => "Intro\n\n![img-0.jpeg](img-0.jpeg)\n\nEnd"],
                                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,QUJD']],
                            ],
                        ],
                    ]],
                ],
            ]],
        ]),
    ]);

    $res = $this->actingAs(pgUser())
        ->post('/playground/run', [
            'capability' => 'ocr_pdf',
            'file' => UploadedFile::fake()->create('doc.pdf', 12, 'application/pdf'),
        ])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->json();

    // The placeholder now points at the inlined data URL, not the bare filename.
    expect($res['text'])->toContain('data:image/jpeg;base64,QUJD')
        ->and($res['text'])->not->toContain('(img-0.jpeg)');
});

test('speech generation via OpenRouter returns audio and passes voice + instructions', function () {
    config(['ai.providers.openrouter.key' => 'sk-or-test']);
    $model = seedModel('speech', 'openrouter', 'openai/gpt-audio-mini');
    setDefault('speech_generation', $model);

    // Audio output is streamed (SSE); the base64 arrives in delta.audio.data.
    Http::fake([
        'openrouter.ai/*' => Http::response(
            'data: {"choices":[{"delta":{"audio":{"data":"QUJD","format":"mp3"}}}]}'."\n\n".'data: [DONE]'."\n",
        ),
    ]);

    $res = $this->actingAs(pgUser())
        ->post('/playground/run', [
            'capability' => 'speech_generation',
            'text' => 'Hola',
            'voice' => 'nova',
            'gender' => 'female',
            'instructions' => 'Mexican Spanish, warm',
        ])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->json();

    expect($res['audio'])->toStartWith('data:audio/wav;base64,');

    Http::assertSent(function ($req) {
        return $req['modalities'] === ['audio', 'text']
            && ($req['audio']['voice'] ?? null) === 'nova'
            && ($req['audio']['format'] ?? null) === 'pcm16'
            && ($req['stream'] ?? null) === true
            && str_contains($req['messages'][0]['content'][0]['text'], 'Mexican Spanish, warm');
    });
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

test('OpenRouter text runs go direct, keeping the raw payload and the serving provider', function () {
    config(['ai.providers.openrouter.key' => 'sk-or-test']);
    $model = seedModel('chat', 'openrouter', 'z-ai/glm-5v-turbo');
    setDefault('chat', $model);

    // Text runs are streamed (TTFT is only measurable from an SSE stream): the
    // provider rides an early chunk, content deltas accumulate, and usage lands
    // in the final chunk via stream_options.include_usage.
    // The final chunk carries OpenRouter's own billed cost, which is
    // authoritative — used verbatim instead of a catalog-price estimate.
    Http::fake([
        'openrouter.ai/*' => Http::response(
            'data: {"id":"gen-1","model":"z-ai/glm-5v-turbo","provider":"DeepInfra","choices":[{"delta":{"role":"assistant"}}]}'."\n\n"
            .'data: {"choices":[{"delta":{"content":"pong"}}]}'."\n\n"
            .'data: {"choices":[{"delta":{},"finish_reason":"stop"}],"usage":{"prompt_tokens":12,"completion_tokens":3,"total_tokens":15,"cost":0.0009}}'."\n\n"
            .'data: [DONE]'."\n",
        ),
    ]);

    $this->actingAs(pgUser())
        ->post('/playground/run', ['capability' => 'text', 'prompt' => 'ping'])
        ->assertOk()
        ->assertJsonPath('text', 'pong')
        ->assertJsonPath('served_by', 'DeepInfra');

    // System prompt travels with the direct call.
    Http::assertSent(fn ($req) => ($req['messages'][0]['role'] ?? null) === 'system'
        && ($req['messages'][1]['role'] ?? null) === 'user');

    $run = PlaygroundRun::sole();
    expect($run->raw['provider'])->toBe('DeepInfra')
        ->and($run->response['served_by'])->toBe('DeepInfra')
        ->and($run->usage['total_tokens'])->toBe(15)
        // Provider-reported cost wins over the catalog estimate (~$0.000026).
        ->and($run->usage['cost'])->toBe(0.0009);

    $user = $run->user;
    $this->actingAs($user ?? pgUser())
        ->get('/playground/history')
        ->assertOk()
        ->assertJsonPath('data.0.served_by', 'DeepInfra');
});

test('lifecycle timestamps persist with millisecond precision', function () {
    $user = pgUser();
    $queued = Carbon::parse('2026-07-20 05:33:12.000');

    $run = new PlaygroundRun;
    $run->forceFill([
        'organization_id' => $user->organization_id,
        'user_id' => $user->id,
        'capability' => 'text',
        'status' => PlaygroundRun::STATUS_OK,
        'queued_at' => $queued,
        'started_at' => $queued->copy()->addMilliseconds(1234),
        'finished_at' => $queued->copy()->addMilliseconds(5678),
    ])->save();

    $fresh = PlaygroundRun::findOrFail($run->id);

    // Without sub-second storage these would quantize to 1000 / 5000.
    expect($fresh->queueWaitMs())->toBe(1234)
        ->and($fresh->endToEndMs())->toBe(5678);
});

test('SDK-path cost applies prompt-cache pricing, not flat input+output', function () {
    $model = AiCatalogModel::create([
        'driver' => 'anthropic',
        'model_id' => 'claude-cache-test',
        'capability' => 'chat',
        'label' => 'Claude Cache Test',
        'is_enabled' => true,
        'sort_order' => 0,
        'input_price_per_mtok' => 3,
        'output_price_per_mtok' => 15,
    ]);
    setDefault('chat', $model);
    Cache::forget('ai_pricing_map');

    // 1M prompt + 1M completion + 1M cached-read input tokens.
    Ai::fakeAgent(AnonymousAgent::class, [
        new TextResponse(
            'answer',
            new Usage(promptTokens: 1_000_000, completionTokens: 1_000_000, cacheReadInputTokens: 1_000_000),
            new Meta('anthropic', 'claude-cache-test'),
        ),
    ]);

    $this->actingAs(pgUser())
        ->post('/playground/run', ['capability' => 'text', 'prompt' => 'hi'])
        ->assertOk();

    $run = PlaygroundRun::sole();
    // 3 (input) + 15 (output) + 0.3 (cache read @ 0.1x) = 18.3 — flat math gives 18.0.
    expect($run->usage['cost'])->toBe(18.3)
        ->and($run->usage['cache_read_tokens'])->toBe(1_000_000);
});

test('the reasoning control is forwarded to OpenRouter (off disables thinking)', function () {
    config(['ai.providers.openrouter.key' => 'sk-or-test']);
    $model = seedModel('chat', 'openrouter', 'z-ai/glm-5v-turbo');
    setDefault('chat', $model);

    Http::fake([
        'openrouter.ai/*' => Http::response(
            'data: {"choices":[{"delta":{"content":"hi"}}]}'."\n\n"
            .'data: {"choices":[{"delta":{},"finish_reason":"stop"}],"usage":{"prompt_tokens":3,"completion_tokens":1,"total_tokens":4}}'."\n\n"
            .'data: [DONE]'."\n",
        ),
    ]);

    $this->actingAs(pgUser())
        ->post('/playground/run', ['capability' => 'text', 'prompt' => 'hi', 'reasoning' => 'off'])
        ->assertOk();

    Http::assertSent(fn ($req) => ($req['reasoning']['enabled'] ?? null) === false);

    // 'default' sends no reasoning field — the model keeps its own behavior.
    expect(PlaygroundRun::sole()->input['reasoning'])->toBe('off');
});

test('a streamed text run captures time-to-first-token', function () {
    config(['ai.providers.openrouter.key' => 'sk-or-test']);
    $model = seedModel('chat', 'openrouter', 'z-ai/glm-5v-turbo');
    setDefault('chat', $model);

    Http::fake([
        'openrouter.ai/*' => Http::response(
            'data: {"provider":"DeepInfra","choices":[{"delta":{"role":"assistant"}}]}'."\n\n"
            .'data: {"choices":[{"delta":{"content":"Hello"}}]}'."\n\n"
            .'data: {"choices":[{"delta":{"content":" there"}}]}'."\n\n"
            .'data: {"choices":[{"delta":{},"finish_reason":"stop"}],"usage":{"prompt_tokens":5,"completion_tokens":2,"total_tokens":7}}'."\n\n"
            .'data: [DONE]'."\n",
        ),
    ]);

    $this->actingAs(pgUser())
        ->post('/playground/run', ['capability' => 'text', 'prompt' => 'hi'])
        ->assertOk()
        ->assertJsonPath('text', 'Hello there')
        ->assertJsonPath('metrics.latency.ttft_ms', fn ($v) => $v !== null && $v >= 0);

    $run = PlaygroundRun::sole();
    expect($run->ttft_ms)->not->toBeNull()
        ->and($run->ttft_ms)->toBeGreaterThanOrEqual(0)
        // TTFT never exceeds the full execution time.
        ->and($run->ttft_ms)->toBeLessThanOrEqual($run->duration_ms + 1);
});

test('a successful run is persisted to history with input, model and output', function () {
    Ai::fakeAgent(AnonymousAgent::class, ['Hello from the text model.']);

    $res = $this->actingAs(pgUser())
        ->post('/playground/run', ['capability' => 'text', 'prompt' => 'Hi'])
        ->assertOk()
        ->json();

    expect($res['run_id'])->toStartWith('pgrun_');

    $run = PlaygroundRun::sole();
    expect($run->capability)->toBe('text')
        ->and($run->status)->toBe('ok')
        ->and($run->input['prompt'])->toBe('Hi')
        ->and($run->output_text)->toBe('Hello from the text model.')
        ->and($run->model)->not->toBeNull()
        ->and($run->duration_ms)->toBeGreaterThanOrEqual(0);
});

test('a failed run is persisted with status error', function () {
    // No reranking default configured — the run fails before reaching a provider.
    $this->actingAs(pgUser())
        ->post('/playground/run', [
            'capability' => 'reranking',
            'query' => 'x',
            'documents' => ['a'],
        ])
        ->assertStatus(422);

    $run = PlaygroundRun::sole();
    expect($run->status)->toBe('error')
        ->and($run->capability)->toBe('reranking')
        ->and($run->model)->toBeNull()
        ->and($run->error)->toContain('No model is configured');
});

test('an OpenRouter run stores the raw provider payload and usage', function () {
    config(['ai.providers.openrouter.key' => 'sk-or-test']);
    $model = seedModel('vision', 'openrouter', 'mistralai/mistral-ocr');
    setDefault('ocr_pdf', $model);

    Http::fake([
        'openrouter.ai/*' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => '',
                    'annotations' => [[
                        'type' => 'file',
                        'file' => ['content' => [['type' => 'text', 'text' => 'Parsed text']]],
                    ]],
                ],
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ]),
    ]);

    $this->actingAs(pgUser())
        ->post('/playground/run', [
            'capability' => 'ocr_pdf',
            'file' => UploadedFile::fake()->create('doc.pdf', 12, 'application/pdf'),
        ])
        ->assertOk();

    $run = PlaygroundRun::sole();
    expect($run->raw['usage']['total_tokens'])->toBe(15)
        ->and($run->usage['total_tokens'])->toBe(15)
        ->and($run->file_meta['name'])->toBe('doc.pdf')
        ->and($run->output_text)->toBe('Parsed text');
});

test('stored responses replace big base64 payloads with a size stub', function () {
    $model = seedModel('image', 'openai', 'gpt-image-1');
    setDefault('image_generation', $model);
    Image::fake([base64_encode(str_repeat('PNGDATA', 200))]);

    $this->actingAs(pgUser())
        ->post('/playground/run', ['capability' => 'image_generation', 'prompt' => 'a cat'])
        ->assertOk();

    $run = PlaygroundRun::sole();
    expect($run->response['image'])->toStartWith('[binary image/png')
        ->and($run->output['image'])->toStartWith('[binary image/png');
});

test('history lists runs newest-first and filters by capability', function () {
    Ai::fakeAgent(AnonymousAgent::class, ['first', 'second']);

    $user = pgUser();
    $this->actingAs($user)->post('/playground/run', ['capability' => 'text', 'prompt' => 'One']);
    $this->actingAs($user)->post('/playground/run', ['capability' => 'coding', 'prompt' => 'Two']);

    $this->actingAs($user)
        ->get('/playground/history')
        ->assertOk()
        ->assertJsonPath('total', 2)
        ->assertJsonPath('data.0.capability', 'coding')
        ->assertJsonPath('data.0.excerpt', 'Two')
        ->assertJsonPath('data.0.user', $user->name);

    $this->actingAs($user)
        ->get('/playground/history?capability=text')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.capability', 'text');
});

test('with an async queue the run is accepted, polled, and finished by the job', function () {
    Queue::fake();
    $user = pgUser();

    $res = $this->actingAs($user)
        ->post('/playground/run', ['capability' => 'text', 'prompt' => 'Hi'])
        ->assertStatus(202)
        ->assertJsonPath('status', 'queued')
        ->json();

    Queue::assertPushedOn('ai', ExecutePlaygroundRun::class);

    // Poll while queued — no payload yet.
    $this->actingAs($user)
        ->get("/playground/run/{$res['run_id']}")
        ->assertOk()
        ->assertJsonPath('status', 'queued')
        ->assertJsonMissingPath('text');

    // Execute the job as a worker would.
    Ai::fakeAgent(AnonymousAgent::class, ['Hello from the worker.']);
    (new ExecutePlaygroundRun($res['run_id'], $user->id, null, ['prompt' => 'Hi']))
        ->handle(app(PlaygroundRunner::class), app(AiProviderService::class));

    // Poll again — terminal payload delivered.
    $this->actingAs($user)
        ->get("/playground/run/{$res['run_id']}")
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('text', 'Hello from the worker.');

    // Telemetry: lifecycle timestamps + execution-only duration are recorded.
    $run = PlaygroundRun::findOrFail($res['run_id']);
    expect($run->queued_at)->not->toBeNull()
        ->and($run->started_at)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->duration_ms)->toBeGreaterThanOrEqual(0)
        ->and($run->queueWaitMs())->toBeGreaterThanOrEqual(0);
});

test('a crashed job lands the run in error instead of leaving it stuck', function () {
    Queue::fake();
    $user = pgUser();

    $res = $this->actingAs($user)
        ->post('/playground/run', ['capability' => 'text', 'prompt' => 'Hi'])
        ->assertStatus(202)
        ->json();

    (new ExecutePlaygroundRun($res['run_id'], $user->id, null, ['prompt' => 'Hi']))
        ->failed(new RuntimeException('worker died'));

    $this->actingAs($user)
        ->get("/playground/run/{$res['run_id']}")
        ->assertOk()
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('error', 'worker died');
});

test('history detail returns the full stored run', function () {
    Ai::fakeAgent(AnonymousAgent::class, ['Hello!']);

    $user = pgUser();
    $runId = $this->actingAs($user)
        ->post('/playground/run', ['capability' => 'text', 'prompt' => 'Hi'])
        ->json('run_id');

    $this->actingAs($user)
        ->get("/playground/history/{$runId}")
        ->assertOk()
        ->assertJsonPath('id', $runId)
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('input.prompt', 'Hi')
        ->assertJsonPath('output_text', 'Hello!')
        ->assertJsonPath('response.text', 'Hello!');
});

test('run metrics split the output cost into reasoning vs answer', function () {
    // Reasoning bills at the completion rate, so the split is exact: the
    // figures mirror a real Grok run (75% of output tokens were reasoning).
    $run = new PlaygroundRun([
        'usage' => ['cost' => 0.0092104, 'prompt_tokens' => 322, 'completion_tokens' => 1464, 'total_tokens' => 1786],
        'raw' => [
            'usage' => [
                'cost_details' => [
                    'upstream_inference_prompt_cost' => 0.0004264,
                    'upstream_inference_completions_cost' => 0.008784,
                ],
                'completion_tokens_details' => ['reasoning_tokens' => 1098],
            ],
        ],
    ]);

    $metrics = $run->metrics();

    expect($metrics['cost']['reasoning'])->toEqualWithDelta(0.006588, 1e-9)
        ->and($metrics['cost']['answer'])->toEqualWithDelta(0.002196, 1e-9)
        ->and($metrics['efficiency']['useful_output_tokens'])->toBe(366);

    // Without reasoning info the split is not measurable — null, never guessed.
    $plain = new PlaygroundRun(['usage' => ['cost' => 0.001, 'completion_tokens' => 100]]);

    expect($plain->metrics()['cost']['reasoning'])->toBeNull()
        ->and($plain->metrics()['cost']['answer'])->toBeNull()
        ->and($plain->metrics()['efficiency']['useful_output_tokens'])->toBeNull();
});
