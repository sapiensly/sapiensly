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

it('emits no Anthropic cache block for other providers (only the reasoning-off default)', function () {
    $agent = makeChatAgent()->withCacheableSystem('FROZEN SYSTEM');

    expect($agent->providerOptions(Lab::OpenAI))->toBe(['reasoning_effort' => 'minimal'])
        ->and($agent->providerOptions(Lab::OpenRouter))->toBe(['reasoning' => ['enabled' => false]])
        ->and($agent->providerOptions(Lab::Gemini))->toBe([]);
});

it('treats an empty cacheable system as a no-op', function () {
    expect(makeChatAgent()->withCacheableSystem('')->providerOptions(Lab::Anthropic))->toBe([])
        ->and(makeChatAgent()->withCacheableSystem(null)->providerOptions(Lab::Anthropic))->toBe([]);
});
