<?php

use App\Enums\Visibility;
use App\Models\AiCatalogModel;
use App\Models\AiProvider;
use App\Models\User;
use App\Services\AiProviderService;
use Laravel\Ai\Enums\Lab;

beforeEach(function () {
    $this->service = app(AiProviderService::class);
});

function globalProvider(string $driver, string $key): AiProvider
{
    return AiProvider::factory()->create([
        'visibility' => Visibility::Global,
        'user_id' => null,
        'organization_id' => null,
        'name' => $driver,
        'driver' => $driver,
        'credentials' => ['api_key' => $key],
        'status' => 'active',
    ]);
}

it('lists enabled catalog chat models regardless of tenant keys', function () {
    $user = User::factory()->create();

    $models = collect($this->service->getEnabledChatModels());

    // Seeded by the catalog migration; no tenant provider exists for the user.
    expect($models->pluck('value'))
        ->toContain('gpt-4o')
        ->toContain('claude-haiku-4-5-20251001')
        // Embeddings models are excluded.
        ->not->toContain('text-embedding-3-small');
});

it('omits disabled catalog models from the chat list', function () {
    // gpt-4-turbo is seeded only under the openai driver.
    AiCatalogModel::query()
        ->where('model_id', 'gpt-4-turbo')
        ->update(['is_enabled' => false]);

    expect(collect($this->service->getEnabledChatModels())->pluck('value'))
        ->not->toContain('gpt-4-turbo');
});

it('tags models byok when the tenant owns the key, system otherwise', function () {
    $user = User::factory()->create();
    AiProvider::factory()->anthropic()->forUser($user)->create(['status' => 'active']);

    $models = collect($this->service->getEnabledChatModels($user))->keyBy('value');

    // Tenant has an Anthropic key → its models are BYOK.
    expect($models['claude-haiku-4-5-20251001']['source'])->toBe('byok')
        // No tenant OpenAI key → openai models fall back to the system key.
        ->and($models['gpt-4-turbo']['source'])->toBe('system');
});

it('omits source when no user is given', function () {
    expect($this->service->getEnabledChatModels()[0])->not->toHaveKey('source');
});

it('resolves a model provider from the catalog driver', function () {
    // gpt-4-turbo is openai-only; gpt-4o is ambiguous (azure + openai).
    expect($this->service->resolveProviderForCatalogModel('gpt-4-turbo'))->toBe(Lab::OpenAI)
        ->and($this->service->resolveProviderForCatalogModel('claude-haiku-4-5-20251001'))->toBe(Lab::Anthropic)
        ->and($this->service->resolveProviderForCatalogModel('totally-made-up-model'))->toBeNull();
});

it('uses the global key as the base layer at runtime', function () {
    $user = User::factory()->create();
    globalProvider('openai', 'sk-global-openai');

    $this->service->applyRuntimeConfig($user);

    expect(config('ai.providers.openai.key'))->toBe('sk-global-openai');
});

it('lets a tenant key override the global key for its driver', function () {
    $user = User::factory()->create();
    globalProvider('anthropic', 'sk-global-anthropic');
    globalProvider('openai', 'sk-global-openai');

    // Tenant brings its own Anthropic key.
    AiProvider::factory()->anthropic()->forUser($user)->create([
        'status' => 'active',
        'credentials' => ['api_key' => 'sk-tenant-anthropic'],
    ]);

    $this->service->applyRuntimeConfig($user);

    expect(config('ai.providers.anthropic.key'))->toBe('sk-tenant-anthropic')
        // Drivers the tenant didn't override keep the global key.
        ->and(config('ai.providers.openai.key'))->toBe('sk-global-openai');
});
