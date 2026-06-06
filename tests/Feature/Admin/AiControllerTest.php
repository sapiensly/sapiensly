<?php

use App\Models\AiCatalogModel;
use App\Models\AiProvider;
use App\Models\AppSetting;
use App\Models\User;
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

test('defaults tab renders per-module primary/fallback with enabled chat models', function () {
    $admin = sysadminForAi();
    seedChatModel();

    $this->actingAs($admin)
        ->get('/admin/ai')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/Ai/Defaults')
            ->has('modules', 4)
            ->has('defaults.chat')
            ->has('defaults.chatbots')
            ->has('chatModels.0'));
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

test('syncProviderModels pulls a direct provider catalog and enables new models', function () {
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
        ->and($rows->firstWhere('model_id', 'claude-sonnet-4-5')->is_enabled)->toBeTrue();
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
    expect($rows->where('model_id', 'gpt-4o')->where('capability', 'chat'))->toHaveCount(1)
        ->and($rows->where('model_id', 'text-embedding-3-small')->where('capability', 'embeddings'))->toHaveCount(1)
        ->and($rows->where('model_id', 'whisper-1'))->toHaveCount(0)
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
