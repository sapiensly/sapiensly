<?php

use App\Models\AiCatalogModel;
use App\Models\AiProvider;
use App\Models\AppSetting;
use App\Models\User;
use App\Services\Ai\AiDefaults;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'sysadmin', 'guard_name' => 'web']);
});

function sysadminForAi(): User
{
    $user = User::factory()->create();
    $user->assignRole('sysadmin');

    return $user;
}

function seedChatModel(string $driver = 'anthropic', string $modelId = 'test-chat-model-1'): AiCatalogModel
{
    return AiCatalogModel::firstOrCreate(
        ['driver' => $driver, 'model_id' => $modelId, 'capability' => 'chat'],
        ['label' => $modelId, 'is_enabled' => true, 'sort_order' => 0],
    );
}

function seedEmbeddingModel(string $modelId = 'test-embedding-model-1'): AiCatalogModel
{
    return AiCatalogModel::firstOrCreate(
        ['driver' => 'openai', 'model_id' => $modelId, 'capability' => 'embeddings'],
        ['label' => $modelId, 'is_enabled' => true, 'sort_order' => 0],
    );
}

test('defaults tab renders per-category primary/fallback with models grouped by capability', function () {
    $admin = sysadminForAi();
    seedChatModel();

    $this->actingAs($admin)
        ->get('/admin/ai')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/Ai/Defaults')
            ->has('modules', count(AiDefaults::MODULES))
            ->has('defaults.chat')
            ->has('defaults.summary_short')
            ->has('defaults.embeddings')
            ->has('defaults.image_generation')
            ->where('moduleCapability.image_generation', 'image')
            ->has('modelsByCapability.chat.0'));
});

test('catalog tab lists every catalog model', function () {
    $admin = sysadminForAi();
    seedChatModel('anthropic', 'test-claude-a');
    seedChatModel('openai', 'test-gpt-a');
    seedEmbeddingModel();

    $initialCount = AiCatalogModel::count();

    $this->actingAs($admin)
        ->get('/admin/ai/catalog')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/Ai/Catalog')
            ->has('models', $initialCount));
});

test('updateDefaults saves a module primary and fallback chat model', function () {
    $admin = sysadminForAi();
    $primary = seedChatModel('anthropic', 'p-model');
    $fallback = seedChatModel('openai', 'f-model');

    $this->actingAs($admin)
        ->patch('/admin/ai/defaults', ['chat' => ['primary' => $primary->id, 'fallback' => $fallback->id]])
        ->assertRedirect();

    expect((string) AppSetting::getValue('admin_v2.ai.chat.primary'))->toBe((string) $primary->id)
        ->and((string) AppSetting::getValue('admin_v2.ai.chat.fallback'))->toBe((string) $fallback->id);
});

test('updateDefaults rejects a non-chat model as a module default', function () {
    $admin = sysadminForAi();
    $embed = seedEmbeddingModel();

    $this->actingAs($admin)
        ->patch('/admin/ai/defaults', ['builder' => ['primary' => $embed->id]])
        ->assertSessionHasErrors(['builder.primary']);
});

function seedCapabilityModel(string $capability, string $driver, string $modelId): AiCatalogModel
{
    return AiCatalogModel::firstOrCreate(
        ['driver' => $driver, 'model_id' => $modelId, 'capability' => $capability],
        ['label' => $modelId, 'is_enabled' => true, 'sort_order' => 0],
    );
}

test('updateDefaults saves an embeddings model for the embeddings category', function () {
    $admin = sysadminForAi();
    $embed = seedEmbeddingModel('emb-default-1');

    $this->actingAs($admin)
        ->patch('/admin/ai/defaults', ['embeddings' => ['primary' => $embed->id]])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect((string) AppSetting::getValue('admin_v2.ai.embeddings.primary'))->toBe((string) $embed->id);
});

test('updateDefaults accepts a vision model for the ocr_pdf category', function () {
    $admin = sysadminForAi();
    $vision = seedCapabilityModel('vision', 'anthropic', 'claude-vision-test');

    $this->actingAs($admin)
        ->patch('/admin/ai/defaults', ['ocr_pdf' => ['primary' => $vision->id]])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect((string) AppSetting::getValue('admin_v2.ai.ocr_pdf.primary'))->toBe((string) $vision->id);
});

test('updateDefaults rejects a chat model for the image_generation category', function () {
    $admin = sysadminForAi();
    $chat = seedChatModel('anthropic', 'not-an-image-model');

    $this->actingAs($admin)
        ->patch('/admin/ai/defaults', ['image_generation' => ['primary' => $chat->id]])
        ->assertSessionHasErrors(['image_generation.primary']);
});

test('OCR-PDF accepts an OpenRouter model and the picker includes it', function () {
    $admin = sysadminForAi();
    $orModel = seedCapabilityModel('chat', 'openrouter', 'mistralai/mistral-ocr');

    // Picker for ocr_pdf lists OpenRouter models (not just vision models).
    $this->actingAs($admin)
        ->get('/admin/ai')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('modelsByCapability.ocr_pdf', fn ($models) => collect($models)->contains(
                fn ($m) => $m['name'] === 'mistralai/mistral-ocr'
            )));

    // And it is accepted as the ocr_pdf default.
    $this->actingAs($admin)
        ->patch('/admin/ai/defaults', ['ocr_pdf' => ['primary' => $orModel->id]])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect((string) AppSetting::getValue('admin_v2.ai.ocr_pdf.primary'))->toBe((string) $orModel->id);
});

test('OCR-Image also accepts an OpenRouter model in its picker', function () {
    $admin = sysadminForAi();
    $orModel = seedCapabilityModel('chat', 'openrouter', 'mistralai/pixtral-12b');

    $this->actingAs($admin)
        ->get('/admin/ai')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('modelsByCapability.ocr_image', fn ($models) => collect($models)->contains(
                fn ($m) => $m['name'] === 'mistralai/pixtral-12b'
            )));

    $this->actingAs($admin)
        ->patch('/admin/ai/defaults', ['ocr_image' => ['primary' => $orModel->id]])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect((string) AppSetting::getValue('admin_v2.ai.ocr_image.primary'))->toBe((string) $orModel->id);
});

test('updateDefaults saves the OCR-PDF OpenRouter engine', function () {
    $admin = sysadminForAi();

    $this->actingAs($admin)
        ->patch('/admin/ai/defaults', ['ocr_pdf_engine' => 'cloudflare-ai'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect((string) AppSetting::getValue('admin_v2.ai.ocr_pdf.engine'))->toBe('cloudflare-ai');
});

test('updateDefaults rejects an unknown OCR-PDF engine', function () {
    $admin = sysadminForAi();

    $this->actingAs($admin)
        ->patch('/admin/ai/defaults', ['ocr_pdf_engine' => 'bogus'])
        ->assertSessionHasErrors(['ocr_pdf_engine']);
});

test('defaults tab exposes the OpenRouter engine state', function () {
    $admin = sysadminForAi();
    seedChatModel();

    $this->actingAs($admin)
        ->get('/admin/ai')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/Ai/Defaults')
            ->has('openRouterActive')
            ->has('pdfEngines', 3)
            ->where('ocrPdfEngine', 'mistral-ocr'));
});

test('toggleModel flips is_enabled on the catalog row', function () {
    $admin = sysadminForAi();
    $model = seedChatModel();

    $this->actingAs($admin)
        ->patch("/admin/ai/catalog/{$model->id}", ['enabled' => false])
        ->assertRedirect();

    expect($model->fresh()->is_enabled)->toBeFalse();
});

test('toggleModel renames the catalog row label without touching is_enabled', function () {
    $admin = sysadminForAi();
    $model = seedChatModel();
    expect($model->is_enabled)->toBeTrue();

    $this->actingAs($admin)
        ->patch("/admin/ai/catalog/{$model->id}", ['label' => 'My Custom Name'])
        ->assertRedirect();

    $fresh = $model->fresh();
    expect($fresh->label)->toBe('My Custom Name')
        ->and($fresh->is_enabled)->toBeTrue();
});

function seedDisabledModel(string $driver = 'xai', string $modelId = 'disabled-model-1'): AiCatalogModel
{
    return AiCatalogModel::create([
        'driver' => $driver,
        'model_id' => $modelId,
        'capability' => 'chat',
        'label' => $modelId,
        'is_enabled' => false,
        'sort_order' => 0,
    ]);
}

function globalProviderFor(string $driver): AiProvider
{
    return AiProvider::factory()->create([
        'visibility' => 'global',
        'user_id' => null,
        'name' => $driver,
        'driver' => $driver,
        'credentials' => ['api_key' => 'sk-'.$driver.'-1234567890abcdef'],
    ]);
}

test('toggleModel blocks enabling a model whose provider is not connected', function () {
    $admin = sysadminForAi();
    config(['ai.providers.xai.key' => '']);
    $model = seedDisabledModel('xai');

    $this->actingAs($admin)
        ->patch("/admin/ai/catalog/{$model->id}", ['enabled' => true])
        ->assertSessionHasErrors('enabled');

    expect($model->fresh()->is_enabled)->toBeFalse();
});

test('toggleModel allows enabling when the provider has a global DB key', function () {
    $admin = sysadminForAi();
    config(['ai.providers.xai.key' => '']);
    $model = seedDisabledModel('xai');
    globalProviderFor('xai');

    $this->actingAs($admin)
        ->patch("/admin/ai/catalog/{$model->id}", ['enabled' => true])
        ->assertSessionHasNoErrors();

    expect($model->fresh()->is_enabled)->toBeTrue();
});

test('toggleModel allows enabling when the provider key lives only in env', function () {
    $admin = sysadminForAi();
    config(['ai.providers.xai.key' => 'sk-xai-env-1234567890abcd']);
    $model = seedDisabledModel('xai');

    $this->actingAs($admin)
        ->patch("/admin/ai/catalog/{$model->id}", ['enabled' => true])
        ->assertSessionHasNoErrors();

    expect($model->fresh()->is_enabled)->toBeTrue();
});

test('toggleModel allows disabling an orphaned model without a connected provider', function () {
    $admin = sysadminForAi();
    config(['ai.providers.xai.key' => '']);
    $model = seedChatModel('xai', 'orphan-1'); // enabled, but provider not connected

    $this->actingAs($admin)
        ->patch("/admin/ai/catalog/{$model->id}", ['enabled' => false])
        ->assertSessionHasNoErrors();

    expect($model->fresh()->is_enabled)->toBeFalse();
});

test('catalog exposes providerConfigured per model', function () {
    $admin = sysadminForAi();
    config(['ai.providers.xai.key' => '']);
    seedDisabledModel('xai', 'unconnected-1');
    seedChatModel('anthropic', 'connected-1');
    globalProviderFor('anthropic');

    $this->actingAs($admin)
        ->get('/admin/ai/catalog')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/Ai/Catalog')
            ->where('models', fn ($models) => collect($models)->contains(
                fn ($m) => $m['name'] === 'unconnected-1' && $m['providerConfigured'] === false
            ))
            ->where('models', fn ($models) => collect($models)->contains(
                fn ($m) => $m['name'] === 'connected-1' && $m['providerConfigured'] === true
            )));
});

test('providers tab lists every known driver grouped direct/broker', function () {
    $admin = sysadminForAi();

    AiProvider::factory()->create([
        'visibility' => 'global',
        'user_id' => null,
        'name' => 'anthropic',
        'driver' => 'anthropic',
        'credentials' => ['api_key' => 'sk-ant-1234567890abcdef', 'rotated_at' => now()->toIso8601String()],
    ]);

    $this->actingAs($admin)
        ->get('/admin/ai/providers')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/Ai/Providers')
            ->has('providers')
            ->where('providers', fn ($providers) => collect($providers)->contains(
                fn ($p) => $p['driver'] === 'anthropic' && $p['kind'] === 'direct' && $p['configured'] === true && $p['masked'] !== null && $p['syncable'] === true
            ))
            ->where('providers', fn ($providers) => collect($providers)->contains(
                fn ($p) => $p['driver'] === 'openrouter' && $p['kind'] === 'broker' && $p['configured'] === false
            )));
});

test('a provider keyed only in .env reflects as configured (source env); a DB row wins as db', function () {
    $admin = sysadminForAi();

    // groq: key lives only in config/.env, no DB row.
    config(['ai.providers.groq.key' => 'sk-groq-env-1234567890abcd']);

    // anthropic: a saved global DB row overrides the env key.
    AiProvider::factory()->create([
        'visibility' => 'global',
        'user_id' => null,
        'name' => 'anthropic',
        'driver' => 'anthropic',
        'credentials' => ['api_key' => 'sk-ant-db-1234567890', 'rotated_at' => now()->toIso8601String()],
    ]);

    $this->actingAs($admin)
        ->get('/admin/ai/providers')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('providers', fn ($providers) => collect($providers)->contains(
                fn ($p) => $p['driver'] === 'groq' && $p['configured'] === true && $p['source'] === 'env' && $p['masked'] !== null
            ))
            ->where('providers', fn ($providers) => collect($providers)->contains(
                fn ($p) => $p['driver'] === 'anthropic' && $p['source'] === 'db'
            )));
});

test('setProviderKey creates a global provider for a driver', function () {
    $admin = sysadminForAi();

    $this->actingAs($admin)
        ->post('/admin/ai/providers/key', [
            'driver' => 'anthropic',
            'credentials' => ['api_key' => 'sk-fresh-test-key-0123456789'],
        ])
        ->assertRedirect();

    $provider = AiProvider::where('visibility', 'global')->where('driver', 'anthropic')->first();
    expect($provider)->not->toBeNull()
        ->and($provider->user_id)->toBeNull()
        ->and($provider->credentials['api_key'])->toBe('sk-fresh-test-key-0123456789')
        ->and($provider->credentials['rotated_at'])->not->toBeNull();
});

test('setProviderKey rotates the key in place without duplicating the row', function () {
    $admin = sysadminForAi();

    AiProvider::factory()->create([
        'visibility' => 'global',
        'user_id' => null,
        'name' => 'anthropic',
        'driver' => 'anthropic',
        'credentials' => ['api_key' => 'sk-old-test-key-must-be-long'],
    ]);

    $this->actingAs($admin)
        ->post('/admin/ai/providers/key', [
            'driver' => 'anthropic',
            'credentials' => ['api_key' => 'sk-rotated-test-key-9876543210'],
        ])
        ->assertRedirect();

    $rows = AiProvider::where('visibility', 'global')->where('driver', 'anthropic')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->credentials['api_key'])->toBe('sk-rotated-test-key-9876543210');
});

test('setProviderKey rejects an unknown driver', function () {
    $admin = sysadminForAi();

    $this->actingAs($admin)
        ->post('/admin/ai/providers/key', [
            'driver' => 'not-a-real-driver',
            'credentials' => ['api_key' => 'sk-fresh-test-key-0123456789'],
        ])
        ->assertSessionHasErrors(['driver']);
});

test('openRouterModels returns the live catalog and current selection', function () {
    Http::fake([
        'openrouter.ai/api/v1/models' => Http::response([
            'data' => [
                [
                    'id' => 'anthropic/claude-sonnet',
                    'name' => 'Anthropic: Claude Sonnet',
                    'context_length' => 200000,
                    'pricing' => ['prompt' => '0.000003', 'completion' => '0.000015'],
                    'architecture' => ['input_modalities' => ['text', 'image']],
                    'supported_parameters' => ['tools', 'temperature'],
                ],
                ['id' => 'openai/gpt-4o', 'name' => 'OpenAI: GPT-4o'],
            ],
        ]),
    ]);

    $admin = sysadminForAi();
    AiProvider::factory()->create([
        'visibility' => 'global',
        'user_id' => null,
        'name' => 'openrouter',
        'driver' => 'openrouter',
        'credentials' => ['api_key' => 'sk-or-1234567890abcdef'],
    ]);
    AiCatalogModel::firstOrCreate(
        ['driver' => 'openrouter', 'model_id' => 'openai/gpt-4o', 'capability' => 'chat'],
        ['label' => 'OpenAI: GPT-4o', 'is_enabled' => true, 'sort_order' => 0],
    );

    $this->actingAs($admin)
        ->getJson('/admin/ai/providers/openrouter/models')
        ->assertOk()
        ->assertJsonCount(2, 'models')
        ->assertJson(['enabled' => ['openai/gpt-4o']])
        // Sorted by label: "Anthropic: …" comes first.
        ->assertJsonPath('models.0.contextWindow', 200000)
        ->assertJsonPath('models.0.inputPricePerMTok', 3)
        ->assertJsonPath('models.0.outputPricePerMTok', 15)
        ->assertJsonPath('models.0.vision', true)
        ->assertJsonPath('models.0.tools', true);
});

test('openRouterModels resolves an env-only key (no DB row)', function () {
    Http::fake([
        'openrouter.ai/api/v1/models' => Http::response([
            'data' => [
                ['id' => 'openai/gpt-4o', 'name' => 'OpenAI: GPT-4o'],
            ],
        ]),
    ]);

    $admin = sysadminForAi();
    config(['ai.providers.openrouter.key' => 'sk-or-env-1234567890abcd']);

    $this->actingAs($admin)
        ->getJson('/admin/ai/providers/openrouter/models')
        ->assertOk()
        ->assertJsonMissingPath('error')
        ->assertJsonCount(1, 'models');
});

test('syncProviderModels syncs a provider keyed only in env (no DB row)', function () {
    Http::fake([
        'api.openai.com/*' => Http::response([
            'data' => [['id' => 'gpt-4o']],
        ]),
    ]);

    $admin = sysadminForAi();
    config(['ai.providers.openai.key' => 'sk-openai-env-1234567890abcd']);

    $this->actingAs($admin)
        ->post('/admin/ai/providers/sync-models', ['driver' => 'openai'])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(
        AiCatalogModel::where('driver', 'openai')->where('model_id', 'gpt-4o')->where('capability', 'chat')->count()
    )->toBe(1);
});

test('saveOpenRouterModels upserts the selection and prunes deselected rows', function () {
    $admin = sysadminForAi();

    AiCatalogModel::firstOrCreate(
        ['driver' => 'openrouter', 'model_id' => 'stale/model', 'capability' => 'chat'],
        ['label' => 'Stale', 'is_enabled' => true, 'sort_order' => 0],
    );

    $this->actingAs($admin)
        ->post('/admin/ai/providers/openrouter/models', [
            'models' => [
                [
                    'id' => 'anthropic/claude-sonnet',
                    'label' => 'Anthropic: Claude Sonnet',
                    'contextWindow' => 200000,
                    'inputPricePerMTok' => 3.0,
                    'outputPricePerMTok' => 15.0,
                ],
            ],
        ])
        ->assertRedirect();

    $rows = AiCatalogModel::where('driver', 'openrouter')->get();
    expect($rows->pluck('model_id')->all())->toBe(['anthropic/claude-sonnet']);

    $row = $rows->first();
    expect($row->context_window)->toBe(200000)
        ->and((float) $row->input_price_per_mtok)->toBe(3.0)
        ->and((float) $row->output_price_per_mtok)->toBe(15.0);
});

test('saveOpenRouterModels keeps a manually edited label but refreshes pricing', function () {
    $admin = sysadminForAi();

    AiCatalogModel::create([
        'driver' => 'openrouter',
        'model_id' => 'anthropic/claude-sonnet',
        'capability' => 'chat',
        'label' => 'My Custom Name',
        'context_window' => 100000,
        'input_price_per_mtok' => 1.0,
        'output_price_per_mtok' => 2.0,
        'is_enabled' => true,
        'sort_order' => 0,
    ]);

    $this->actingAs($admin)
        ->post('/admin/ai/providers/openrouter/models', [
            'models' => [
                [
                    'id' => 'anthropic/claude-sonnet',
                    'label' => 'Anthropic: Claude Sonnet',
                    'contextWindow' => 200000,
                    'inputPricePerMTok' => 3.0,
                    'outputPricePerMTok' => 15.0,
                ],
            ],
        ])
        ->assertRedirect();

    $row = AiCatalogModel::where('driver', 'openrouter')
        ->where('model_id', 'anthropic/claude-sonnet')
        ->first();

    // Label is preserved, context/pricing refreshed.
    expect($row->label)->toBe('My Custom Name')
        ->and($row->context_window)->toBe(200000)
        ->and((float) $row->input_price_per_mtok)->toBe(3.0);
});

test('syncProviderModels pulls a direct provider catalog and registers new models disabled', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'data' => [
                ['id' => 'claude-sonnet-4-5', 'display_name' => 'Claude Sonnet 4.5', 'type' => 'model'],
                ['id' => 'claude-opus-4-5', 'display_name' => 'Claude Opus 4.5', 'type' => 'model'],
            ],
        ]),
    ]);

    $admin = sysadminForAi();
    AiProvider::factory()->create([
        'visibility' => 'global',
        'user_id' => null,
        'name' => 'anthropic',
        'driver' => 'anthropic',
        'credentials' => ['api_key' => 'sk-ant-1234567890abcdef'],
    ]);

    $this->actingAs($admin)
        ->post('/admin/ai/providers/sync-models', ['driver' => 'anthropic'])
        ->assertRedirect();

    $rows = AiCatalogModel::where('driver', 'anthropic')->where('capability', 'chat')->get();
    expect($rows->pluck('model_id')->all())->toContain('claude-sonnet-4-5', 'claude-opus-4-5')
        ->and($rows->firstWhere('model_id', 'claude-sonnet-4-5')->label)->toBe('Claude Sonnet 4.5')
        // New models land disabled — the admin opts each into the catalog.
        ->and($rows->firstWhere('model_id', 'claude-sonnet-4-5')->is_enabled)->toBeFalse();
});

test('syncProviderModels keeps an existing enabled model enabled', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'data' => [
                ['id' => 'claude-sonnet-4-5', 'display_name' => 'Claude Sonnet 4.5'],
                ['id' => 'claude-brand-new', 'display_name' => 'Claude Brand New'],
            ],
        ]),
    ]);

    $admin = sysadminForAi();
    AiProvider::factory()->create([
        'visibility' => 'global',
        'user_id' => null,
        'name' => 'anthropic',
        'driver' => 'anthropic',
        'credentials' => ['api_key' => 'sk-ant-1234567890abcdef'],
    ]);
    AiCatalogModel::create([
        'driver' => 'anthropic',
        'model_id' => 'claude-sonnet-4-5',
        'capability' => 'chat',
        'label' => 'Claude Sonnet 4.5',
        'is_enabled' => true,
        'sort_order' => 0,
    ]);

    $this->actingAs($admin)
        ->post('/admin/ai/providers/sync-models', ['driver' => 'anthropic'])
        ->assertRedirect();

    $rows = AiCatalogModel::where('driver', 'anthropic')->where('capability', 'chat')->get();
    // Existing row keeps its enabled status; the brand-new one lands disabled.
    expect($rows->firstWhere('model_id', 'claude-sonnet-4-5')->is_enabled)->toBeTrue()
        ->and($rows->firstWhere('model_id', 'claude-brand-new')->is_enabled)->toBeFalse();
});

test('syncProviderModels classifies openai models and skips non-text ones', function () {
    Http::fake([
        'api.openai.com/*' => Http::response([
            'data' => [
                ['id' => 'gpt-4o'],
                ['id' => 'text-embedding-3-small'],
                ['id' => 'whisper-1'],
                ['id' => 'dall-e-3'],
            ],
        ]),
    ]);

    $admin = sysadminForAi();
    AiProvider::factory()->openai()->create([
        'visibility' => 'global',
        'user_id' => null,
        'credentials' => ['api_key' => 'sk-openai-1234567890abcdef'],
    ]);

    $this->actingAs($admin)
        ->post('/admin/ai/providers/sync-models', ['driver' => 'openai'])
        ->assertRedirect();

    $rows = AiCatalogModel::where('driver', 'openai')->get();
    // Sync only registers text models (chat/embeddings); audio/image models are
    // skipped — whisper-1 is only ever present as a seeded transcription row,
    // never added as chat by sync, and dall-e-3 is not imported at all.
    expect($rows->where('model_id', 'gpt-4o')->where('capability', 'chat'))->toHaveCount(1)
        ->and($rows->where('model_id', 'text-embedding-3-small')->where('capability', 'embeddings'))->toHaveCount(1)
        ->and($rows->where('model_id', 'whisper-1')->where('capability', 'chat'))->toHaveCount(0)
        ->and($rows->where('model_id', 'dall-e-3'))->toHaveCount(0);
});

test('syncProviderModels preserves an existing disabled toggle', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'data' => [['id' => 'claude-sonnet-4-5', 'display_name' => 'Claude Sonnet 4.5']],
        ]),
    ]);

    $admin = sysadminForAi();
    AiProvider::factory()->create([
        'visibility' => 'global',
        'user_id' => null,
        'name' => 'anthropic',
        'driver' => 'anthropic',
        'credentials' => ['api_key' => 'sk-ant-1234567890abcdef'],
    ]);
    AiCatalogModel::create([
        'driver' => 'anthropic',
        'model_id' => 'claude-sonnet-4-5',
        'capability' => 'chat',
        'label' => 'old label',
        'is_enabled' => false,
        'sort_order' => 0,
    ]);

    $this->actingAs($admin)
        ->post('/admin/ai/providers/sync-models', ['driver' => 'anthropic'])
        ->assertRedirect();

    $row = AiCatalogModel::where('driver', 'anthropic')->where('model_id', 'claude-sonnet-4-5')->first();
    expect($row->is_enabled)->toBeFalse()
        ->and($row->label)->toBe('Claude Sonnet 4.5');
});

test('syncProviderModels needs a configured key', function () {
    $admin = sysadminForAi();
    $before = AiCatalogModel::where('driver', 'anthropic')->count();

    $this->actingAs($admin)
        ->post('/admin/ai/providers/sync-models', ['driver' => 'anthropic'])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(AiCatalogModel::where('driver', 'anthropic')->count())->toBe($before);
});

test('syncProviderModels rejects a non-syncable driver', function () {
    $admin = sysadminForAi();

    $this->actingAs($admin)
        ->post('/admin/ai/providers/sync-models', ['driver' => 'voyageai'])
        ->assertSessionHasErrors(['driver']);
});

test('non-sysadmin is blocked from /admin/ai', function () {
    $member = User::factory()->create();

    $this->actingAs($member)->get('/admin/ai')->assertForbidden();
    $this->actingAs($member)->get('/admin/ai/catalog')->assertForbidden();
    $this->actingAs($member)->get('/admin/ai/providers')->assertForbidden();
    $this->actingAs($member)
        ->post('/admin/ai/providers/key', [
            'driver' => 'anthropic',
            'credentials' => ['api_key' => 'sk-fresh-test-key-0123456789'],
        ])
        ->assertForbidden();
    $this->actingAs($member)
        ->patch('/admin/ai/defaults', ['chat' => ['primary' => null]])
        ->assertForbidden();
});

test('catalog model test invokes the exact model id and returns success', function () {
    $admin = sysadminForAi();
    config(['ai.providers.anthropic.key' => 'sk-ant-env-1234567890abcd']);
    $model = seedChatModel('anthropic', 'claude-haiku-4-5-20251001');
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(['content' => []], 200)]);

    $this->actingAs($admin)
        ->postJson("/admin/ai/catalog/{$model->id}/test")
        ->assertOk()
        ->assertJson(['success' => true]);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.anthropic.com/v1/messages'
        && $request['model'] === 'claude-haiku-4-5-20251001');
});

test('catalog model test hits chat-completions for openai-compatible drivers', function () {
    $admin = sysadminForAi();
    config(['ai.providers.openai.key' => 'sk-openai-env-1234567890abcd']);
    $model = seedChatModel('openai', 'gpt-4o-mini');
    Http::fake(['api.openai.com/v1/chat/completions' => Http::response(['choices' => []], 200)]);

    $this->actingAs($admin)
        ->postJson("/admin/ai/catalog/{$model->id}/test")
        ->assertOk()
        ->assertJson(['success' => true]);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/chat/completions'
        && $request['model'] === 'gpt-4o-mini');
});

test('catalog model test probes embeddings for an embedding model', function () {
    $admin = sysadminForAi();
    config(['ai.providers.voyageai.key' => 'pa-voyage-env-1234567890abcd']);
    $model = AiCatalogModel::create([
        'driver' => 'voyageai',
        'model_id' => 'voyage-probe-test',
        'capability' => 'embeddings',
        'label' => 'Voyage Probe',
        'is_enabled' => true,
        'sort_order' => 0,
    ]);
    Http::fake(['api.voyageai.com/v1/embeddings' => Http::response(['data' => []], 200)]);

    $this->actingAs($admin)
        ->postJson("/admin/ai/catalog/{$model->id}/test")
        ->assertOk()
        ->assertJson(['success' => true]);

    Http::assertSent(fn ($request) => $request['model'] === 'voyage-probe-test');
});

test('catalog model test surfaces a bad model id as a failure', function () {
    $admin = sysadminForAi();
    config(['ai.providers.anthropic.key' => 'sk-ant-env-1234567890abcd']);
    $model = seedChatModel('anthropic', 'claude-typo-999');
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'model not found']], 404)]);

    $this->actingAs($admin)
        ->postJson("/admin/ai/catalog/{$model->id}/test")
        ->assertOk()
        ->assertJson(['success' => false]);
});

test('catalog model test fails when the provider is not connected', function () {
    $admin = sysadminForAi();
    config(['ai.providers.xai.key' => '']);
    $model = seedChatModel('xai', 'grok-test');

    $this->actingAs($admin)
        ->postJson("/admin/ai/catalog/{$model->id}/test")
        ->assertOk()
        ->assertJson(['success' => false]);
});

test('test-connection probes a provider keyed in the DB and returns success', function () {
    $admin = sysadminForAi();
    globalProviderFor('anthropic');
    Http::fake(['api.anthropic.com/*' => Http::response(['ok' => true], 200)]);

    $this->actingAs($admin)
        ->postJson('/admin/ai/test-connection', ['driver' => 'anthropic'])
        ->assertOk()
        ->assertJson(['success' => true]);
});

test('test-connection probes a provider keyed only in env', function () {
    $admin = sysadminForAi();
    config(['ai.providers.groq.key' => 'sk-groq-env-1234567890abcd']);
    Http::fake(['api.groq.com/*' => Http::response(['data' => []], 200)]);

    $this->actingAs($admin)
        ->postJson('/admin/ai/test-connection', ['driver' => 'groq'])
        ->assertOk()
        ->assertJson(['success' => true]);
});

test('test-connection reports an unconfigured provider as a failure', function () {
    $admin = sysadminForAi();
    config(['ai.providers.xai.key' => '']);

    $this->actingAs($admin)
        ->postJson('/admin/ai/test-connection', ['driver' => 'xai'])
        ->assertOk()
        ->assertJson(['success' => false]);
});

test('test-connection surfaces an upstream failure', function () {
    $admin = sysadminForAi();
    globalProviderFor('anthropic');
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'bad key']], 401)]);

    $this->actingAs($admin)
        ->postJson('/admin/ai/test-connection', ['driver' => 'anthropic'])
        ->assertOk()
        ->assertJson(['success' => false]);
});

test('test-connection rejects an unknown driver', function () {
    $admin = sysadminForAi();

    $this->actingAs($admin)
        ->postJson('/admin/ai/test-connection', ['driver' => 'not-a-driver'])
        ->assertStatus(422);
});
