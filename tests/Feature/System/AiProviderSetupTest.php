<?php

use App\Models\AiProvider;
use App\Models\User;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('storing one provider with both a chat and an embeddings model creates a single provider', function () {
    actingAs($this->user)->post('/system/ai-providers', [
        'driver' => 'openai',
        'credentials' => ['api_key' => 'sk-test-key'],
        'chat_model_id' => 'gpt-4o',
        'embeddings_model_id' => 'text-embedding-3-small',
        'make_default_chat' => true,
        'make_default_embeddings' => true,
    ])->assertRedirect('/system/ai-providers');

    $providers = AiProvider::where('user_id', $this->user->id)->get();
    expect($providers)->toHaveCount(1);

    $provider = $providers->first();
    expect($provider->driver)->toBe('openai')
        ->and($provider->is_default)->toBeTrue()
        ->and($provider->is_default_embeddings)->toBeTrue()
        ->and($provider->credentials['api_key'])->toBe('sk-test-key');

    $modelIds = collect($provider->models)->pluck('id')->all();
    expect($modelIds)->toContain('gpt-4o')->toContain('text-embedding-3-small');
});

test('two separate submissions create two providers', function () {
    actingAs($this->user)->post('/system/ai-providers', [
        'driver' => 'anthropic',
        'credentials' => ['api_key' => 'sk-ant-test'],
        'chat_model_id' => 'claude-sonnet-4-20250514',
        'make_default_chat' => true,
    ])->assertRedirect('/system/ai-providers');

    actingAs($this->user)->post('/system/ai-providers', [
        'driver' => 'openai',
        'credentials' => ['api_key' => 'sk-openai-test'],
        'embeddings_model_id' => 'text-embedding-3-small',
        'make_default_embeddings' => true,
    ])->assertRedirect('/system/ai-providers');

    $providers = AiProvider::where('user_id', $this->user->id)->get()->keyBy('driver');
    expect($providers)->toHaveCount(2)
        ->and($providers['anthropic']->is_default)->toBeTrue()
        ->and($providers['anthropic']->is_default_embeddings)->toBeFalse()
        ->and($providers['openai']->is_default_embeddings)->toBeTrue()
        ->and($providers['openai']->is_default)->toBeFalse();
});

test('adding a provider keeps existing defaults when not opted in', function () {
    AiProvider::factory()->openai()->forUser($this->user)->default()->defaultEmbeddings()->create();

    actingAs($this->user)->post('/system/ai-providers', [
        'driver' => 'anthropic',
        'credentials' => ['api_key' => 'sk-ant-new'],
        'chat_model_id' => 'claude-sonnet-4-20250514',
    ])->assertRedirect('/system/ai-providers');

    $defaults = AiProvider::where('user_id', $this->user->id)->where('is_default', true)->get();
    expect($defaults)->toHaveCount(1)
        ->and($defaults->first()->driver)->toBe('openai');

    $anthropic = AiProvider::where('user_id', $this->user->id)->where('driver', 'anthropic')->firstOrFail();
    expect($anthropic->is_default)->toBeFalse();
});

test('storing rejects a non-chat model as the chat model', function () {
    actingAs($this->user)->post('/system/ai-providers', [
        'driver' => 'openai',
        'credentials' => ['api_key' => 'sk-test'],
        'chat_model_id' => 'text-embedding-3-small',
    ])->assertSessionHasErrors(['chat_model_id']);

    expect(AiProvider::where('user_id', $this->user->id)->count())->toBe(0);
});

test('storing rejects a non-embeddings model as the embeddings model', function () {
    actingAs($this->user)->post('/system/ai-providers', [
        'driver' => 'openai',
        'credentials' => ['api_key' => 'sk-test'],
        'embeddings_model_id' => 'gpt-4o',
    ])->assertSessionHasErrors(['embeddings_model_id']);

    expect(AiProvider::where('user_id', $this->user->id)->count())->toBe(0);
});

test('re-adding the same driver updates credentials and models instead of duplicating', function () {
    actingAs($this->user)->post('/system/ai-providers', [
        'driver' => 'openai',
        'credentials' => ['api_key' => 'sk-old'],
        'chat_model_id' => 'gpt-4o',
    ])->assertRedirect('/system/ai-providers');

    actingAs($this->user)->post('/system/ai-providers', [
        'driver' => 'openai',
        'credentials' => ['api_key' => 'sk-new'],
        'chat_model_id' => 'gpt-4o-mini',
        'embeddings_model_id' => 'text-embedding-3-large',
    ])->assertRedirect('/system/ai-providers');

    $providers = AiProvider::where('user_id', $this->user->id)->get();
    expect($providers)->toHaveCount(1);

    $provider = $providers->first();
    expect($provider->credentials['api_key'])->toBe('sk-new');

    $modelIds = collect($provider->models)->pluck('id')->all();
    expect($modelIds)->toContain('gpt-4o-mini')
        ->toContain('text-embedding-3-large')
        ->not->toContain('gpt-4o');
});
