<?php

use App\Ai\BuilderAgent;
use App\Ai\ChatAgent;
use App\Ai\ExpressGateAgent;
use App\Ai\RuntimeAgent;
use App\Services\Ai\ReasoningOptions;
use Laravel\Ai\Enums\Lab;

/**
 * Reasoning is off platform-wide by default. Only OpenRouter and OpenAI need an
 * active field to disable it; Anthropic doesn't reason unless thinking is
 * explicitly enabled, so 'off' is its default and a no-op here.
 */
it('translates reasoning modes to the right per-provider field', function () {
    expect(ReasoningOptions::forProvider('off', Lab::OpenRouter))->toBe(['reasoning' => ['enabled' => false]]);
    expect(ReasoningOptions::forProvider('high', Lab::OpenRouter))->toBe(['reasoning' => ['effort' => 'high']]);
    expect(ReasoningOptions::forProvider('off', Lab::OpenAI))->toBe(['reasoning_effort' => 'minimal']);
    expect(ReasoningOptions::forProvider('low', Lab::OpenAI))->toBe(['reasoning_effort' => 'low']);
    // Anthropic off is a no-op (already its default); default/null leave the model's own.
    expect(ReasoningOptions::forProvider('off', Lab::Anthropic))->toBe([]);
    expect(ReasoningOptions::forProvider('default', Lab::OpenRouter))->toBe([]);
    expect(ReasoningOptions::forProvider(null, Lab::OpenRouter))->toBe([]);
});

it('defaults chat and runtime agents to reasoning off, honoring an override', function () {
    expect((new ChatAgent('sys', [], []))->providerOptions(Lab::OpenRouter))
        ->toBe(['reasoning' => ['enabled' => false]]);
    expect((new RuntimeAgent('sys', [], []))->providerOptions(Lab::OpenRouter))
        ->toBe(['reasoning' => ['enabled' => false]]);

    $chat = (new ChatAgent('sys', [], []))->withReasoning('high');
    expect($chat->providerOptions(Lab::OpenRouter))->toBe(['reasoning' => ['effort' => 'high']]);

    // 'default' is the escape hatch back to the model's own behavior.
    $runtime = (new RuntimeAgent('sys', [], []))->withReasoning('default');
    expect($runtime->providerOptions(Lab::OpenRouter))->toBe([]);
});

it('forces reasoning off in the builder and express-gate agents', function () {
    expect((new BuilderAgent('sys', [], []))->providerOptions(Lab::OpenRouter))
        ->toBe(['reasoning' => ['enabled' => false]]);

    $gate = new ExpressGateAgent('instructions', fn ($s) => [], null);
    expect($gate->providerOptions(Lab::OpenRouter))->toBe(['reasoning' => ['enabled' => false]]);
});
