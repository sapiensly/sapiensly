<?php

namespace App\Ai;

use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;

/**
 * The Builder's chat agent. Pins max output tokens to 32k (Opus caps output at
 * 32k; a 64k default request is rejected) and — via HasProviderOptions — marks
 * the (large, static) system prompt as an Anthropic prompt-cache breakpoint.
 *
 * Caching matters here: the builder's system prompt + the 16 tool definitions
 * are a stable prefix re-sent on every turn of a conversation. Without a cache
 * marker they are re-billed at full price each turn (~9k tokens). Marking the
 * system block `cache_control: ephemeral` caches everything before it (tools +
 * system), so turns after the first bill that prefix at ~0.1x. Only Anthropic
 * is reachable through this SDK hook (its `system` is a top-level field the
 * providerOptions merge can replace); other providers no-op. Opt-in (default
 * off) so any one-shot use of this class never emits a cache marker.
 */
#[MaxTokens(32000)]
class BuilderAgent extends AnonymousAgent implements HasProviderOptions
{
    private ?string $cacheableSystem = null;

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
        if ($this->cacheableSystem === null || trim($this->cacheableSystem) === '') {
            return [];
        }

        if ($provider === Lab::Anthropic || $provider === 'anthropic') {
            return [
                'system' => [[
                    'type' => 'text',
                    'text' => $this->cacheableSystem,
                    'cache_control' => ['type' => 'ephemeral'],
                ]],
            ];
        }

        return [];
    }
}
