<?php

use App\Jobs\RecomputeEmbeddingsJob;
use App\Models\AiCatalogModel;
use App\Models\AiProvider;
use App\Models\AppSetting;
use App\Models\KnowledgeBase;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
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

test('defaults tab renders with current settings + enabled models', function () {
    $admin = sysadminForAi();
    seedChatModel();
    seedEmbeddingModel();

    $this->actingAs($admin)
        ->get('/admin/ai')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/Ai/Defaults')
            ->has('defaults')
            ->has('chatModels.0')
            ->has('embeddingModels.0')
            ->has('keys'));
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

test('updateDefaults saves the primary chat model', function () {
    $admin = sysadminForAi();
    $model = seedChatModel();

    $this->actingAs($admin)
        ->patch('/admin/ai/defaults', ['primaryChatModelId' => $model->id])
        ->assertRedirect();

    expect((string) AppSetting::getValue('admin_v2.ai.primary_chat_model_id'))
        ->toBe((string) $model->id);
});

test('updateDefaults rejects a non-chat model as primary', function () {
    $admin = sysadminForAi();
    $embed = seedEmbeddingModel();

    $this->actingAs($admin)
        ->patch('/admin/ai/defaults', ['primaryChatModelId' => $embed->id])
        ->assertSessionHasErrors(['primaryChatModelId']);
});

test('updateDefaults clamps temperature to 0..2', function () {
    $admin = sysadminForAi();

    $this->actingAs($admin)
        ->patch('/admin/ai/defaults', ['temperature' => 5])
        ->assertSessionHasErrors(['temperature']);

    $this->actingAs($admin)
        ->patch('/admin/ai/defaults', ['temperature' => 0.3])
        ->assertRedirect();
});

test('embedding model swap dispatches RecomputeEmbeddingsJob per KB', function () {
    Queue::fake();

    $admin = sysadminForAi();
    $initial = seedEmbeddingModel('text-embedding-3-small');
    $replacement = seedEmbeddingModel('text-embedding-3-large');

    AppSetting::setValue('admin_v2.ai.embedding_model_id', $initial->id);

    KnowledgeBase::factory()->count(2)->create(['user_id' => $admin->id]);

    $response = $this->actingAs($admin)
        ->patch('/admin/ai/defaults', ['embeddingModelId' => $replacement->id]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    Queue::assertPushed(RecomputeEmbeddingsJob::class, 2);
});

test('first-time embedding assignment does not dispatch the reindex job', function () {
    Queue::fake();

    $admin = sysadminForAi();
    $first = seedEmbeddingModel();
    KnowledgeBase::factory()->create(['user_id' => $admin->id]);

    // No existing embedding stored — saving for the first time is just a set.
    $this->actingAs($admin)
        ->patch('/admin/ai/defaults', ['embeddingModelId' => $first->id])
        ->assertRedirect();

    Queue::assertNotPushed(RecomputeEmbeddingsJob::class);
});

test('toggleModel flips is_enabled on the catalog row', function () {
    $admin = sysadminForAi();
    $model = seedChatModel();

    $this->actingAs($admin)
        ->patch("/admin/ai/catalog/{$model->id}", ['enabled' => false])
        ->assertRedirect();

    expect($model->fresh()->is_enabled)->toBeFalse();
});

test('rotateKey writes the new api key to the provider and stamps rotated_at', function () {
    $admin = sysadminForAi();
    $provider = AiProvider::factory()->create([
        'visibility' => 'global',
        'driver' => 'anthropic',
        'credentials' => ['api_key' => 'sk-old-test-key-must-be-long'],
    ]);

    $this->actingAs($admin)
        ->post("/admin/ai/providers/{$provider->id}/rotate-key", [
            'api_key' => 'sk-fresh-test-key-0123456789',
        ])
        ->assertRedirect();

    $fresh = $provider->fresh();
    expect($fresh->credentials['api_key'])->toBe('sk-fresh-test-key-0123456789')
        ->and($fresh->credentials['rotated_at'])->not->toBeNull();
});

test('testConnection returns ok when a provider has credentials', function () {
    $admin = sysadminForAi();
    $provider = AiProvider::factory()->create([
        'visibility' => 'global',
        'credentials' => ['api_key' => 'sk-test-1234567890abcdef'],
    ]);

    $this->actingAs($admin)
        ->postJson('/admin/ai/test-connection', [
            'driver' => $provider->driver,
            'provider_id' => $provider->id,
        ])
        ->assertOk()
        ->assertJson(['ok' => true]);
});

test('non-sysadmin is blocked from /admin/ai', function () {
    $member = User::factory()->create();

    $this->actingAs($member)->get('/admin/ai')->assertForbidden();
    $this->actingAs($member)->get('/admin/ai/catalog')->assertForbidden();
    $this->actingAs($member)
        ->patch('/admin/ai/defaults', ['temperature' => 0.2])
        ->assertForbidden();
});
