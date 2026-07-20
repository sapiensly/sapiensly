<?php

namespace App\Ai;

use App\Services\Ai\ReasoningOptions;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;

/**
 * The general Chat agent. Identical to AnonymousAgent but pins max output
 * tokens to 32k -- valid for every Claude 4.x model (Opus caps at 32k) and
 * a sane ceiling for a single chat turn.
 *
 * Implements HasProviderOptions to enable Anthropic prompt caching. When a
 * frozen (stable across turns) system prefix is registered via
 * {@see self::withCacheableSystem()}, the Anthropic request's `system` is sent
 * as a content block carrying `cache_control: ephemeral`, so later turns that
 * reuse the same prefix are billed as cached input (~0.1x). Only Anthropic is
 * reachable through this SDK hook (its `system` is a top-level field the
 * providerOptions merge can replace); OpenAI and OpenRouter-to-OpenAI cache a
 * stable prefix automatically with no marker, and OpenRouter-to-Anthropic is
 * NOT reachable here (the SDK folds its system into `messages`). Anthropic also
 * only caches prefixes above a model minimum (~1-4k tokens); below that the
 * marker is a silent no-op. The setter is opt-in (default off), so the one-shot
 * title/summary agents that reuse this class never emit cache markers.
 */
#[MaxTokens(32000)]
class ChatAgent extends AnonymousAgent implements HasProviderOptions
{
    private ?string $cacheableSystem = null;

    private bool $webSearch = false;

    private ?int $webSearchMaxResults = null;

    // Default off platform-wide: reasoning is opt-in, set per agent/chatbot.
    private ?string $reasoning = 'off';

    /**
     * Set the reasoning preference for this turn ('off'|'low'|'medium'|'high'),
     * inherited from the agent/chatbot the conversation runs. Default off.
     */
    public function withReasoning(?string $mode): static
    {
        $this->reasoning = $mode;

        return $this;
    }

    /**
     * Register a frozen system prefix as cacheable for providers that support
     * explicit cache breakpoints (Anthropic). No-op for other providers.
     */
    public function withCacheableSystem(?string $system): static
    {
        $this->cacheableSystem = $system;

        return $this;
    }

    /**
     * Enable OpenRouter's `web` plugin for this turn — its way of doing web
     * search, since it can't take the native WebSearch provider tool. Optional
     * max_results bounds how many results it pulls. No-op for other providers.
     */
    public function withWebSearch(?int $maxResults = null): static
    {
        $this->webSearch = true;
        $this->webSearchMaxResults = $maxResults;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $options = ReasoningOptions::forProvider($this->reasoning, $provider);

        if (($provider === Lab::Anthropic || $provider === 'anthropic')
            && $this->cacheableSystem !== null && trim($this->cacheableSystem) !== '') {
            $options['system'] = [[
                'type' => 'text',
                'text' => $this->cacheableSystem,
                'cache_control' => ['type' => 'ephemeral'],
            ]];
        }

        if (($provider === Lab::OpenRouter || $provider === 'openrouter') && $this->webSearch) {
            $plugin = ['id' => 'web'];
            if ($this->webSearchMaxResults !== null) {
                $plugin['max_results'] = $this->webSearchMaxResults;
            }
            $options['plugins'] = [$plugin];
        }

        return $options;
    }
}
