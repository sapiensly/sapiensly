<?php

use App\Ai\RuntimeAgent;
use Laravel\Ai\Enums\Lab;

it('marks the system prefix cacheable for Anthropic when registered', function () {
    $agent = (new RuntimeAgent('You are a helpful agent.', [], []))
        ->withCacheableSystem('You are a helpful agent.');

    $options = $agent->providerOptions(Lab::Anthropic);

    expect($options['system'][0]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and($options['system'][0]['text'])->toBe('You are a helpful agent.');
});

it('emits no provider options without a registered prefix or for other providers', function () {
    $bare = new RuntimeAgent('instructions', [], []);
    expect($bare->providerOptions(Lab::Anthropic))->toBe([]);

    $registered = (new RuntimeAgent('instructions', [], []))->withCacheableSystem('instructions');
    expect($registered->providerOptions(Lab::OpenAI))->toBe([]);
});
