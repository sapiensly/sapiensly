<?php

use App\Ai\Gateway\CachingAnthropicGateway;
use App\Ai\Gateway\CachingOpenRouterGateway;
use Laravel\Ai\AiManager;

/**
 * The gateways only matter if the container actually hands them out.
 *
 * A binding that silently stops applying reproduces the original bug exactly —
 * the build still runs, nothing errors, and the bill quietly doubles — so the
 * wiring is pinned here rather than trusted.
 */
it('serves OpenRouter text through the caching gateway', function () {
    config()->set('ai.providers.openrouter.api_key', 'test-key');

    $provider = app(AiManager::class)->textProvider('openrouter');

    expect($provider->textGateway())->toBeInstanceOf(CachingOpenRouterGateway::class);
});

it('serves Anthropic text through the caching gateway', function () {
    config()->set('ai.providers.anthropic.api_key', 'test-key');

    $provider = app(AiManager::class)->textProvider('anthropic');

    expect($provider->textGateway())->toBeInstanceOf(CachingAnthropicGateway::class);
});
