<?php

use App\Models\AiProvider;
use App\Models\User;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('storing with same driver for LLM and embeddings creates one provider with both defaults', function () {
    actingAs($this->user)->post('/system/ai-providers', [
        'llm' => [
            'driver' => 'openai',
            'model_id' => 'gpt-4o',
            'credentials' => ['api_key' => 'sk-test-key'],
        ],
        'embeddings' => [
            'driver' => 'openai',
            'model_id' => 'text-embedding-3-small',
            'credentials' => ['api_key' => 'sk-test-key'],
        ],
    ])->assertRedirect('/system/ai-providers');

    $providers = AiProvider::where('user_id', $this->user->id)->get();

    expect($providers)->toHaveCount(1);

    $provider = $providers->first();
    expect($provider->driver)->toBe('openai');
    expect($provider->is_default)->toBeTrue();
    expect($provider->is_default_embeddings)->toBeTrue();
    expect($provider->credentials['api_key'])->toBe('sk-test-key');

    $modelIds = collect($provider->models)->pluck('id')->all();
    expect($modelIds)->toContain('gpt-4o');
    expect($modelIds)->toContain('text-embedding-3-small');
});

test('storing with different drivers creates two providers', function () {
    actingAs($this->user)->post('/system/ai-providers', [
        'llm' => [
            'driver' => 'anthropic',
            'model_id' => 'claude-sonnet-4-20250514',
            'credentials' => ['api_key' => 'sk-ant-test'],
        ],
        'embeddings' => [
            'driver' => 'openai',
            'model_id' => 'text-embedding-3-small',
            'credentials' => ['api_key' => 'sk-openai-test'],
        ],
    ])->assertRedirect('/system/ai-providers');

    $providers = AiProvider::where('user_id', $this->user->id)->get()->keyBy('driver');

    expect($providers)->toHaveCount(2);

    $anthropic = $providers['anthropic'];
    expect($anthropic->is_default)->toBeTrue();
    expect($anthropic->is_default_embeddings)->toBeFalse();
    expect(collect($anthropic->models)->pluck('id')->all())->toBe(['claude-sonnet-4-20250514']);

    $openai = $providers['openai'];
    expect($openai->is_default)->toBeFalse();
    expect($openai->is_default_embeddings)->toBeTrue();
    expect(collect($openai->models)->pluck('id')->all())->toBe(['text-embedding-3-small']);
});

test('storing clears existing defaults in the user context', function () {
    AiProvider::factory()->openai()->forUser($this->user)->default()->defaultEmbeddings()->create();

    actingAs($this->user)->post('/system/ai-providers', [
        'llm' => [
            'driver' => 'anthropic',
            'model_id' => 'claude-sonnet-4-20250514',
            'credentials' => ['api_key' => 'sk-ant-new'],
        ],
        'embeddings' => [
            'driver' => 'openai',
            'model_id' => 'text-embedding-3-large',
            'credentials' => ['api_key' => 'sk-openai-new'],
        ],
    ])->assertRedirect('/system/ai-providers');

    // Exactly one default for each capability across the account context
    $defaults = AiProvider::where('user_id', $this->user->id)->where('is_default', true)->get();
    expect($defaults)->toHaveCount(1);
    expect($defaults->first()->driver)->toBe('anthropic');

    $defaultEmbeddings = AiProvider::where('user_id', $this->user->id)->where('is_default_embeddings', true)->get();
    expect($defaultEmbeddings)->toHaveCount(1);
    expect($defaultEmbeddings->first()->driver)->toBe('openai');
});

test('storing rejects a non-chat model for the LLM slot', function () {
    actingAs($this->user)->post('/system/ai-providers', [
        'llm' => [
            'driver' => 'openai',
            'model_id' => 'text-embedding-3-small',
            'credentials' => ['api_key' => 'sk-test'],
        ],
        'embeddings' => [
            'driver' => 'openai',
            'model_id' => 'text-embedding-3-small',
            'credentials' => ['api_key' => 'sk-test'],
        ],
    ])->assertSessionHasErrors(['llm.model_id']);

    expect(AiProvider::where('user_id', $this->user->id)->count())->toBe(0);
});

test('storing rejects a non-embeddings model for the embeddings slot', function () {
    actingAs($this->user)->post('/system/ai-providers', [
        'llm' => [
            'driver' => 'openai',
            'model_id' => 'gpt-4o',
            'credentials' => ['api_key' => 'sk-test'],
        ],
        'embeddings' => [
            'driver' => 'openai',
            'model_id' => 'gpt-4o',
            'credentials' => ['api_key' => 'sk-test'],
        ],
    ])->assertSessionHasErrors(['embeddings.model_id']);

    expect(AiProvider::where('user_id', $this->user->id)->count())->toBe(0);
});

test('resubmitting with a previously used driver updates credentials instead of duplicating', function () {
    // First setup
    actingAs($this->user)->post('/system/ai-providers', [
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
    ])->assertRedirect('/system/ai-providers');

    // Second setup with same driver but new key and new model
    actingAs($this->user)->post('/system/ai-providers', [
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
    ])->assertRedirect('/system/ai-providers');

    $providers = AiProvider::where('user_id', $this->user->id)->get();
    expect($providers)->toHaveCount(1);

    $provider = $providers->first();
    expect($provider->credentials['api_key'])->toBe('sk-new');

    $modelIds = collect($provider->models)->pluck('id')->all();
    expect($modelIds)->toContain('gpt-4o-mini');
    expect($modelIds)->toContain('text-embedding-3-large');
    expect($modelIds)->not->toContain('gpt-4o');
});
