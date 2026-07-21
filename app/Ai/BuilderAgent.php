<?php

namespace App\Ai;

use App\Services\Ai\ReasoningOptions;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;

/**
 * The Builder's chat agent. Caps max output tokens per model and — via
 * HasProviderOptions — marks the (large, static) system prompt as an Anthropic
 * prompt-cache breakpoint.
 *
 * The output cap is resolved through {@see self::maxTokens()} (the SDK's
 * TextGenerationOptions prefers an agent method over a static attribute), so it
 * can vary per resolved model — call {@see self::forModel()} before prompting.
 * 32k is the safe default: Anthropic Opus rejects a 64k request, and the
 * OpenRouter chat models the builder can run on support >= 32k (or OpenRouter
 * clamps to the model max). Lower a specific model in self::MAX_TOKENS only if
 * the eval shows it 400s on 32k.
 *
 * Caching matters here: the builder's system prompt + the tool definitions are a
 * stable prefix re-sent on every turn of a conversation. Without a cache marker
 * they are re-billed at full price each turn (~9k tokens). Marking the system
 * block `cache_control: ephemeral` caches everything before it (tools + system),
 * so turns after the first bill that prefix at ~0.1x. This SDK hook only reaches
 * Anthropic (its `system` is a top-level field the providerOptions merge can
 * replace); OpenRouter/OpenAI no-op here and rely on automatic provider-side
 * caching of the stable prefix instead. Opt-in (default off) so any one-shot use
 * of this class never emits a cache marker.
 */
class BuilderAgent extends AnonymousAgent implements HasProviderOptions
{
    /**
     * Per-model output-token cap. Models not listed use self::DEFAULT_MAX_TOKENS.
     *
     * @var array<string, int>
     */
    private const MAX_TOKENS = [];

    private const DEFAULT_MAX_TOKENS = 32000;

    private ?string $cacheableSystem = null;

    private ?string $model = null;

    /**
     * Pin the resolved model so {@see self::maxTokens()} can cap per model.
     * Called by BuilderAiService once the model for the turn is known.
     */
    public function forModel(?string $model): static
    {
        $this->model = $model;

        return $this;
    }

    /**
     * The output-token cap for the resolved model. The SDK calls this in
     * preference to a #[MaxTokens] attribute (see TextGenerationOptions::resolve).
     */
    public function maxTokens(): int
    {
        return self::MAX_TOKENS[$this->model] ?? self::DEFAULT_MAX_TOKENS;
    }

    /**
     * Register the (frozen, per-conversation) system prefix as cacheable.
     */
    public function withCacheableSystem(?string $system): static
    {
        $this->cacheableSystem = $system;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        // The builder does structured, tool-driven authoring — reasoning adds
        // cost and latency without helping, so it is always off (unless the
        // model mandates reasoning, where an explicit disable would 400).
        $options = ReasoningOptions::forProvider('off', $provider, $this->model);

        if ($this->cacheableSystem !== null && trim($this->cacheableSystem) !== ''
            && ($provider === Lab::Anthropic || $provider === 'anthropic')) {
            $options['system'] = [[
                'type' => 'text',
                'text' => $this->cacheableSystem,
                'cache_control' => ['type' => 'ephemeral'],
            ]];
        }

        return $options;
    }
}
