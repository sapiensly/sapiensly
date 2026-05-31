<?php

use App\Models\AiProvider;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('adds a single new provider without disturbing existing defaults', function () {
    $chat = AiProvider::factory()->anthropic()->forUser($this->user)->create([
        'is_default' => true,
        'status' => 'active',
    ]);
    $embeddings = AiProvider::factory()->openai()->forUser($this->user)->create([
        'is_default_embeddings' => true,
        'status' => 'active',
    ]);

    $this->actingAs($this->user)
        ->post('/system/ai-providers', [
            'driver' => 'mistral',
            'credentials' => ['api_key' => 'sk-test'],
            'chat_model_id' => 'mistral-large-latest',
        ])
        ->assertRedirect(route('system.ai-providers.index'));

    expect(AiProvider::where('user_id', $this->user->id)->count())->toBe(3);

    $new = AiProvider::where('driver', 'mistral')->where('user_id', $this->user->id)->firstOrFail();
    expect($new->is_default)->toBeFalse()
        ->and($new->is_default_embeddings)->toBeFalse()
        ->and($new->models[0]['id'])->toBe('mistral-large-latest');

    // Existing defaults are untouched.
    expect($chat->refresh()->is_default)->toBeTrue()
        ->and($embeddings->refresh()->is_default_embeddings)->toBeTrue();
});

it('makes the first provider the default when opted in', function () {
    $this->actingAs($this->user)
        ->post('/system/ai-providers', [
            'driver' => 'anthropic',
            'credentials' => ['api_key' => 'sk-test'],
            'chat_model_id' => 'claude-sonnet-4-20250514',
            'make_default_chat' => true,
        ])
        ->assertRedirect();

    $provider = AiProvider::where('driver', 'anthropic')->where('user_id', $this->user->id)->firstOrFail();
    expect($provider->is_default)->toBeTrue();
});

it('moves the default chat flag to the new provider when opted in', function () {
    $old = AiProvider::factory()->anthropic()->forUser($this->user)->create(['is_default' => true]);

    $this->actingAs($this->user)
        ->post('/system/ai-providers', [
            'driver' => 'mistral',
            'credentials' => ['api_key' => 'sk-test'],
            'chat_model_id' => 'mistral-large-latest',
            'make_default_chat' => true,
        ])
        ->assertRedirect();

    expect($old->refresh()->is_default)->toBeFalse()
        ->and(AiProvider::where('user_id', $this->user->id)->where('is_default', true)->count())->toBe(1);
});

it('requires at least one model', function () {
    $this->actingAs($this->user)
        ->post('/system/ai-providers', [
            'driver' => 'anthropic',
            'credentials' => ['api_key' => 'sk-test'],
        ])
        ->assertSessionHasErrors('chat_model_id');
});

it('rejects a model that is not in the driver catalog', function () {
    $this->actingAs($this->user)
        ->post('/system/ai-providers', [
            'driver' => 'anthropic',
            'credentials' => ['api_key' => 'sk-test'],
            'chat_model_id' => 'not-a-real-model',
        ])
        ->assertSessionHasErrors('chat_model_id');
});
