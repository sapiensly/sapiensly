<?php

use App\Ai\ExpressGateAgent;
use Laravel\Ai\Enums\Lab;

it('marks the stable context as an Anthropic cacheable system block', function () {
    $agent = new ExpressGateAgent('Eres el fit-check.', fn ($s) => [], "CATÁLOGO:\n{\"catalogo\":[]}");

    foreach ([Lab::Anthropic, 'anthropic'] as $provider) {
        $options = $agent->providerOptions($provider);
        expect($options['system'][0])->toBe(['type' => 'text', 'text' => 'Eres el fit-check.'])
            ->and($options['system'][1]['cache_control'])->toBe(['type' => 'ephemeral'])
            ->and($options['system'][1]['text'])->toContain('CATÁLOGO');
    }
});

it('folds the context into instructions and emits no marker for other providers', function () {
    $agent = new ExpressGateAgent('Eres el fit-check.', fn ($s) => [], "CATÁLOGO:\n{\"catalogo\":[]}");

    expect($agent->providerOptions(Lab::OpenRouter))->toBe([])
        ->and($agent->providerOptions('openai'))->toBe([])
        ->and($agent->instructions())->toStartWith('Eres el fit-check.')
        ->and($agent->instructions())->toContain('CATÁLOGO');
});

it('is a no-op without stable context — plain instructions, no system override', function () {
    $agent = new ExpressGateAgent('Eres la voz.', fn ($s) => []);

    expect($agent->providerOptions(Lab::Anthropic))->toBe([])
        ->and($agent->instructions())->toBe('Eres la voz.');
});
