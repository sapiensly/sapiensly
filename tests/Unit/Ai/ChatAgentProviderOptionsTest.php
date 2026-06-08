<?php

use App\Ai\ChatAgent;
use Laravel\Ai\Enums\Lab;

function makeChatAgent(): ChatAgent
{
    return new ChatAgent(instructions: 'sys', messages: [], tools: []);
}

it('returns no provider options when no cacheable system is set', function () {
    expect(makeChatAgent()->providerOptions(Lab::Anthropic))->toBe([]);
});

it('emits an Anthropic system cache block when a cacheable system is set', function () {
    $agent = makeChatAgent()->withCacheableSystem('FROZEN SYSTEM');

    expect($agent->providerOptions(Lab::Anthropic))->toBe([
        'system' => [[
            'type' => 'text',
            'text' => 'FROZEN SYSTEM',
            'cache_control' => ['type' => 'ephemeral'],
        ]],
    ]);
});

it('emits nothing for non-Anthropic providers even with a cacheable system', function () {
    $agent = makeChatAgent()->withCacheableSystem('FROZEN SYSTEM');

    expect($agent->providerOptions(Lab::OpenAI))->toBe([])
        ->and($agent->providerOptions(Lab::OpenRouter))->toBe([])
        ->and($agent->providerOptions(Lab::Gemini))->toBe([]);
});

it('treats an empty cacheable system as a no-op', function () {
    expect(makeChatAgent()->withCacheableSystem('')->providerOptions(Lab::Anthropic))->toBe([])
        ->and(makeChatAgent()->withCacheableSystem(null)->providerOptions(Lab::Anthropic))->toBe([]);
});
