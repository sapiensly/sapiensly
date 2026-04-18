<?php

use App\Enums\Visibility;
use App\Models\AiCatalogModel;
use App\Models\AiProvider;
use App\Models\User;
use App\Services\AiProviderService;
use Database\Seeders\RolesAndPermissionsSeeder;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('sysadmin');
});

test('non-admin cannot access the global AI page', function () {
    $user = User::factory()->create();

    actingAs($user)->get('/admin/system/global-ai')->assertForbidden();
});

test('non-admin cannot store global providers', function () {
    $user = User::factory()->create();

    actingAs($user)->post('/admin/system/global-ai', [
        'llm' => [
            'driver' => 'openai',
            'model_id' => 'gpt-4o',
            'credentials' => ['api_key' => 'sk'],
        ],
        'embeddings' => [
            'driver' => 'openai',
            'model_id' => 'text-embedding-3-small',
            'credentials' => ['api_key' => 'sk'],
        ],
    ])->assertForbidden();

    expect(AiProvider::count())->toBe(0);
});

test('admin storing with same driver creates one global provider with both defaults', function () {
    actingAs($this->admin)->post('/admin/system/global-ai', [
        'llm' => [
            'driver' => 'openai',
            'model_id' => 'gpt-4o',
            'credentials' => ['api_key' => 'sk-global'],
        ],
        'embeddings' => [
            'driver' => 'openai',
            'model_id' => 'text-embedding-3-small',
            'credentials' => ['api_key' => 'sk-global'],
        ],
    ])->assertRedirect('/admin/system/global-ai');

    $globals = AiProvider::where('visibility', Visibility::Global)->get();
    expect($globals)->toHaveCount(1);

    $provider = $globals->first();
    expect($provider->user_id)->toBeNull();
    expect($provider->organization_id)->toBeNull();
    expect($provider->driver)->toBe('openai');
    expect($provider->is_default)->toBeTrue();
    expect($provider->is_default_embeddings)->toBeTrue();
    expect($provider->credentials['api_key'])->toBe('sk-global');

    $modelIds = collect($provider->models)->pluck('id')->all();
    expect($modelIds)->toContain('gpt-4o');
    expect($modelIds)->toContain('text-embedding-3-small');
});

test('admin storing with distinct drivers creates two global providers', function () {
    actingAs($this->admin)->post('/admin/system/global-ai', [
        'llm' => [
            'driver' => 'anthropic',
            'model_id' => 'claude-sonnet-4-20250514',
            'credentials' => ['api_key' => 'sk-ant'],
        ],
        'embeddings' => [
            'driver' => 'openai',
            'model_id' => 'text-embedding-3-small',
            'credentials' => ['api_key' => 'sk-openai'],
        ],
    ])->assertRedirect('/admin/system/global-ai');

    $globals = AiProvider::where('visibility', Visibility::Global)->get()->keyBy('driver');
    expect($globals)->toHaveCount(2);

    expect($globals['anthropic']->is_default)->toBeTrue();
    expect($globals['anthropic']->is_default_embeddings)->toBeFalse();
    expect($globals['openai']->is_default)->toBeFalse();
    expect($globals['openai']->is_default_embeddings)->toBeTrue();
});

test('admin storing does not touch user-level providers', function () {
    $user = User::factory()->create();
    $userProvider = AiProvider::factory()->openai()->forUser($user)->default()->defaultEmbeddings()->create();

    actingAs($this->admin)->post('/admin/system/global-ai', [
        'llm' => [
            'driver' => 'anthropic',
            'model_id' => 'claude-sonnet-4-20250514',
            'credentials' => ['api_key' => 'sk-ant'],
        ],
        'embeddings' => [
            'driver' => 'openai',
            'model_id' => 'text-embedding-3-small',
            'credentials' => ['api_key' => 'sk-openai'],
        ],
    ])->assertRedirect('/admin/system/global-ai');

    $userProvider->refresh();
    expect($userProvider->is_default)->toBeTrue();
    expect($userProvider->is_default_embeddings)->toBeTrue();
});

test('resubmitting updates the existing global providers instead of duplicating', function () {
    actingAs($this->admin)->post('/admin/system/global-ai', [
        'llm' => [
            'driver' => 'openai',
            'model_id' => 'gpt-4o',
            'credentials' => ['api_key' => 'sk-old'],
        ],
        'embeddings' => [
            'driver' => 'openai',
            'model_id' => 'text-embedding-3-small',
            'credentials' => ['api_key' => 'sk-old'],
        ],
    ])->assertRedirect('/admin/system/global-ai');

    actingAs($this->admin)->post('/admin/system/global-ai', [
        'llm' => [
            'driver' => 'openai',
            'model_id' => 'gpt-4o-mini',
            'credentials' => ['api_key' => 'sk-new'],
        ],
        'embeddings' => [
            'driver' => 'openai',
            'model_id' => 'text-embedding-3-large',
            'credentials' => ['api_key' => 'sk-new'],
        ],
    ])->assertRedirect('/admin/system/global-ai');

    $globals = AiProvider::where('visibility', Visibility::Global)->get();
    expect($globals)->toHaveCount(1);

    $provider = $globals->first();
    expect($provider->credentials['api_key'])->toBe('sk-new');

    $modelIds = collect($provider->models)->pluck('id')->all();
    expect($modelIds)->toContain('gpt-4o-mini');
    expect($modelIds)->toContain('text-embedding-3-large');
    expect($modelIds)->not->toContain('gpt-4o');
});

test('admin can add a new catalog model', function () {
    actingAs($this->admin)->post('/admin/system/global-ai/catalog', [
        'driver' => 'openai',
        'model_id' => 'gpt-5',
        'label' => 'GPT-5',
        'capability' => 'chat',
    ])->assertRedirect('/admin/system/global-ai');

    $row = AiCatalogModel::where('model_id', 'gpt-5')->first();
    expect($row)->not->toBeNull();
    expect($row->driver)->toBe('openai');
    expect($row->capability)->toBe('chat');
    expect($row->is_enabled)->toBeTrue();
});

test('admin cannot create duplicate catalog entries (driver+model+capability)', function () {
    actingAs($this->admin)->post('/admin/system/global-ai/catalog', [
        'driver' => 'openai',
        'model_id' => 'gpt-4o',
        'label' => 'GPT-4o Duplicate',
        'capability' => 'chat',
    ])->assertSessionHasErrors('model_id');
});

test('admin can update a catalog model label and enabled flag', function () {
    $row = AiCatalogModel::where('driver', 'openai')->where('model_id', 'gpt-4o')->first();

    actingAs($this->admin)->patch("/admin/system/global-ai/catalog/{$row->id}", [
        'driver' => $row->driver,
        'model_id' => $row->model_id,
        'label' => 'GPT-4o Custom',
        'capability' => $row->capability,
        'is_enabled' => false,
    ])->assertRedirect('/admin/system/global-ai');

    $row->refresh();
    expect($row->label)->toBe('GPT-4o Custom');
    expect($row->is_enabled)->toBeFalse();
});

test('admin can delete a catalog model', function () {
    $row = AiCatalogModel::where('driver', 'openai')->where('model_id', 'gpt-4o')->first();

    actingAs($this->admin)->delete("/admin/system/global-ai/catalog/{$row->id}")
        ->assertRedirect('/admin/system/global-ai');

    expect(AiCatalogModel::find($row->id))->toBeNull();
});

test('disabled catalog models are not exposed to the drivers endpoint', function () {
    // Disable every chat row for anthropic
    AiCatalogModel::where('driver', 'anthropic')->update(['is_enabled' => false]);

    $service = app(AiProviderService::class);
    $drivers = collect($service->getAvailableDrivers())->keyBy('value');

    expect($drivers['anthropic']['models'])->toBe([]);
});

test('non-admin cannot manage the catalog', function () {
    $user = User::factory()->create();

    actingAs($user)->post('/admin/system/global-ai/catalog', [
        'driver' => 'openai',
        'model_id' => 'gpt-new',
        'label' => 'GPT New',
        'capability' => 'chat',
    ])->assertForbidden();
});

test('admin store rejects a non-chat model for the LLM slot', function () {
    actingAs($this->admin)->post('/admin/system/global-ai', [
        'llm' => [
            'driver' => 'openai',
            'model_id' => 'text-embedding-3-small',
            'credentials' => ['api_key' => 'sk'],
        ],
        'embeddings' => [
            'driver' => 'openai',
            'model_id' => 'text-embedding-3-small',
            'credentials' => ['api_key' => 'sk'],
        ],
    ])->assertSessionHasErrors(['llm.model_id']);

    expect(AiProvider::count())->toBe(0);
});
