<?php

use App\Ai\BuilderAgent;
use App\Ai\Gateway\CachingAnthropicGateway;
use Laravel\Ai\Enums\Lab;

/**
 * The moving cache breakpoint: with only the system prompt cacheable, every
 * round trip of an agentic turn re-billed the whole growing history at the
 * full input rate (measured live: ~60% of a landing build's Anthropic bill).
 * The gateway marks the last cacheable block of the last message instead, so
 * each trip re-reads the prior prefix at ~0.1x and writes only the suffix.
 */
it('marks the last cacheable block of the last message', function () {
    $body = CachingAnthropicGateway::withMovingCacheBreakpoint([
        'messages' => [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'build me a landing']]],
            ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'propose_change', 'input' => (object) []]]],
            ['role' => 'user', 'content' => [
                ['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => '{"ok":true}'],
                ['type' => 'tool_result', 'tool_use_id' => 't2', 'content' => '{"ok":true}'],
            ]],
        ],
    ]);

    $lastMessage = $body['messages'][2]['content'];
    expect($lastMessage[1]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and($lastMessage[0])->not->toHaveKey('cache_control')     // only ONE breakpoint
        ->and($body['messages'][0]['content'][0])->not->toHaveKey('cache_control');
});

it('walks back past a trailing non-cacheable block', function () {
    // Thinking blocks cannot carry cache_control — the breakpoint must land on
    // the nearest legal block instead of producing an invalid request.
    $body = CachingAnthropicGateway::withMovingCacheBreakpoint([
        'messages' => [
            ['role' => 'assistant', 'content' => [
                ['type' => 'text', 'text' => 'plan…'],
                ['type' => 'thinking', 'thinking' => ''],
            ]],
        ],
    ]);

    expect($body['messages'][0]['content'][0]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and($body['messages'][0]['content'][1])->not->toHaveKey('cache_control');
});

it('wraps bare string content into a cached text block', function () {
    $body = CachingAnthropicGateway::withMovingCacheBreakpoint([
        'messages' => [['role' => 'user', 'content' => 'hello']],
    ]);

    expect($body['messages'][0]['content'])->toBe([[
        'type' => 'text',
        'text' => 'hello',
        'cache_control' => ['type' => 'ephemeral'],
    ]]);
});

it('leaves a body without messages untouched', function () {
    expect(CachingAnthropicGateway::withMovingCacheBreakpoint(['messages' => []]))
        ->toBe(['messages' => []]);
});

it('is requested by the BuilderAgent only for Anthropic and only with a cacheable system', function () {
    $agent = (new BuilderAgent('sys', [], []))->withCacheableSystem('the system prompt');

    // Anthropic with a cacheable system → both the system block and the flag.
    $anthropic = $agent->providerOptions(Lab::Anthropic);
    expect($anthropic[CachingAnthropicGateway::CACHE_MESSAGES_FLAG] ?? null)->toBeTrue()
        ->and($anthropic['system'][0]['cache_control'])->toBe(['type' => 'ephemeral']);

    // OpenRouter relies on automatic provider-side caching — no flag.
    expect($agent->providerOptions(Lab::OpenRouter))
        ->not->toHaveKey(CachingAnthropicGateway::CACHE_MESSAGES_FLAG);

    // One-shot use without a cacheable system emits no cache markers at all.
    expect((new BuilderAgent('sys', [], []))->providerOptions(Lab::Anthropic))
        ->not->toHaveKey(CachingAnthropicGateway::CACHE_MESSAGES_FLAG);
});
