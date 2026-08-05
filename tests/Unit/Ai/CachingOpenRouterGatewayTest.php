<?php

use App\Ai\BuilderAgent;
use App\Ai\Gateway\CachingAnthropicGateway;
use App\Ai\Gateway\CachingOpenRouterGateway;
use Laravel\Ai\Enums\Lab;

/**
 * OpenRouter was assumed to cache the stable prefix by itself. It does for most
 * of its catalog, but not for the Anthropic models it brokers: their caching is
 * explicit whoever fronts them. Measured live on the same builder brief through
 * the same broker — grok read 533k cached tokens, `~anthropic/claude-haiku-latest`
 * read zero and cost MORE than the pricier model it was chosen over.
 */
it('marks the system message and the last message', function () {
    $body = CachingOpenRouterGateway::withCacheBreakpoints([
        'messages' => [
            ['role' => 'system', 'content' => 'the builder prompt'],
            ['role' => 'user', 'content' => 'build me an app'],
            ['role' => 'assistant', 'content' => 'on it'],
        ],
    ]);

    // Anthropic caches everything BEFORE a breakpoint, so the frozen prefix and
    // the grown history need one each.
    expect($body['messages'][0]['content'][0]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and($body['messages'][2]['content'][0]['cache_control'])->toBe(['type' => 'ephemeral'])
        // …and nothing in between, or the request blows the breakpoint budget.
        ->and($body['messages'][1]['content'])->toBe('build me an app');
});

it('promotes a string body to the only shape that can carry a breakpoint', function () {
    // OpenRouter forwards cache_control to Anthropic, but the OpenAI-compatible
    // `content: "…"` string has nowhere to hang it.
    $body = CachingOpenRouterGateway::withCacheBreakpoints([
        'messages' => [['role' => 'system', 'content' => 'hello']],
    ]);

    expect($body['messages'][0]['content'])->toBe([[
        'type' => 'text',
        'text' => 'hello',
        'cache_control' => ['type' => 'ephemeral'],
    ]]);
});

it('marks the last text part when the content is already structured', function () {
    $body = CachingOpenRouterGateway::withCacheBreakpoints([
        'messages' => [
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'first'],
                ['type' => 'text', 'text' => 'second'],
            ]],
        ],
    ]);

    expect($body['messages'][0]['content'][1]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and($body['messages'][0]['content'][0])->not->toHaveKey('cache_control');
});

it('leaves a message with no text part alone', function () {
    // A bare tool call carries nothing that may legally hold a breakpoint.
    $body = CachingOpenRouterGateway::withCacheBreakpoints([
        'messages' => [
            ['role' => 'system', 'content' => 'prompt'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 't1']]],
        ],
    ]);

    expect($body['messages'][1])->not->toHaveKey('cache_control')
        ->and($body['messages'][1]['content'])->toBeNull();
});

it('handles a conversation whose only message is the system prompt', function () {
    $body = CachingOpenRouterGateway::withCacheBreakpoints([
        'messages' => [['role' => 'system', 'content' => 'prompt']],
    ]);

    // System and last are the same message — one breakpoint, not two passes.
    expect($body['messages'])->toHaveCount(1)
        ->and($body['messages'][0]['content'][0]['cache_control'])->toBe(['type' => 'ephemeral']);
});

it('recognises Anthropic models under both spellings the catalog uses', function () {
    expect(CachingOpenRouterGateway::isAnthropicModel('anthropic/claude-3-haiku'))->toBeTrue()
        ->and(CachingOpenRouterGateway::isAnthropicModel('~anthropic/claude-haiku-latest'))->toBeTrue()
        ->and(CachingOpenRouterGateway::isAnthropicModel('  ~Anthropic/Claude-Opus-Latest '))->toBeTrue()
        // Everything else on OpenRouter either caches server-side or ignores it.
        ->and(CachingOpenRouterGateway::isAnthropicModel('~x-ai/grok-latest'))->toBeFalse()
        ->and(CachingOpenRouterGateway::isAnthropicModel('openai/gpt-5-mini'))->toBeFalse()
        ->and(CachingOpenRouterGateway::isAnthropicModel('deepseek/deepseek-v4-pro'))->toBeFalse();
});

it('asks for caching on an OpenRouter Anthropic model and nowhere else', function () {
    $agent = (new BuilderAgent('sys', [], []))->withCacheableSystem('the builder prompt');

    $brokered = $agent->forModel('~anthropic/claude-haiku-latest')->providerOptions(Lab::OpenRouter);
    expect($brokered)->toHaveKey(CachingOpenRouterGateway::CACHE_MESSAGES_FLAG)
        // The system override is Anthropic-direct only: OpenRouter carries the
        // system prompt as a message, so overriding a top-level key does nothing.
        ->and($brokered)->not->toHaveKey('system');

    $grok = $agent->forModel('~x-ai/grok-latest')->providerOptions(Lab::OpenRouter);
    expect($grok)->not->toHaveKey(CachingOpenRouterGateway::CACHE_MESSAGES_FLAG);
});

it('emits no marker when nothing was registered as cacheable', function () {
    $options = (new BuilderAgent('sys', [], []))
        ->forModel('~anthropic/claude-haiku-latest')
        ->providerOptions(Lab::OpenRouter);

    expect($options)->not->toHaveKey(CachingOpenRouterGateway::CACHE_MESSAGES_FLAG);
});

it('shares one opt-in flag across both gateways', function () {
    // An agent asks for conversation caching once; whichever gateway serves the
    // turn honours it. Drift here would silently disable caching on one side.
    expect(CachingOpenRouterGateway::CACHE_MESSAGES_FLAG)
        ->toBe(CachingAnthropicGateway::CACHE_MESSAGES_FLAG);
});
