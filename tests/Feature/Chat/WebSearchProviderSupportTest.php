<?php

use App\Services\Chat\ChatAiService;
use Laravel\Ai\Enums\Lab;

/**
 * Web search is a provider-native tool only some gateways implement. Attaching
 * it to a provider that doesn't (e.g. OpenRouter) throws and used to kill the
 * whole agent turn; the service must instead recognise the capability and
 * degrade. This locks the capability matrix that drives that decision.
 */
function supportsWebSearch(Lab $provider): bool
{
    $method = new ReflectionMethod(ChatAiService::class, 'providerSupportsWebSearch');

    return $method->invoke(app(ChatAiService::class), $provider);
}

it('reports web search support only for the gateways that implement it', function () {
    expect(supportsWebSearch(Lab::Anthropic))->toBeTrue();
    expect(supportsWebSearch(Lab::Gemini))->toBeTrue();
    expect(supportsWebSearch(Lab::OpenAI))->toBeTrue();
});

it('reports no web search support for OpenRouter and other gateways', function () {
    expect(supportsWebSearch(Lab::OpenRouter))->toBeFalse();
    expect(supportsWebSearch(Lab::Groq))->toBeFalse();
    expect(supportsWebSearch(Lab::DeepSeek))->toBeFalse();
    expect(supportsWebSearch(Lab::Mistral))->toBeFalse();
});
