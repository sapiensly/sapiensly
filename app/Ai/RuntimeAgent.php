<?php

namespace App\Ai;

use App\Services\Ai\ReasoningOptions;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;

/**
 * The SDK agent for internal agent runs (LLMService — invoke_agent, standalone
 * agent conversations, consultations). Identical to AnonymousAgent, plus the
 * same Anthropic prompt-caching hook as {@see ChatAgent}: registering a frozen
 * system prefix via {@see self::withCacheableSystem()} sends Anthropic's
 * `system` as a content block carrying `cache_control: ephemeral`.
 *
 * This matters far more here than in chat: every agent run merges the platform
 * tool catalogue plus the agent's own tools (an MCP connection can expand into
 * ~100 definitions), and tools render BEFORE system in the Anthropic prompt —
 * so the system breakpoint caches the entire tool block too. Without it, every
 * tool round-trip of an agentic turn re-bills the full prefix at 1x input
 * price (the failure mode that burned 3.5M uncached tokens in one afternoon).
 */
class RuntimeAgent extends AnonymousAgent implements HasProviderOptions
{
    private ?string $cacheableSystem = null;

    // Default off platform-wide: reasoning is opt-in, set per agent.
    private ?string $reasoning = 'off';

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
     * Set the reasoning preference for this run ('off'|'low'|'medium'|'high'),
     * from the agent's own config. Default off — reasoning is opt-in per agent.
     */
    public function withReasoning(?string $mode): static
    {
        $this->reasoning = $mode;

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

        return $options;
    }
}
