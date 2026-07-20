<?php

namespace App\Ai;

use App\Services\Ai\ReasoningOptions;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * The Express pipeline's gate agent: one bounded structured question, ZERO
 * tools, tiny instructions. This shape — not the model — is what makes a gate
 * fast: no 40k prefill, no tool schemas, no history to re-read, and the SDK
 * enforces the JSON schema on the way out.
 *
 * A gate may carry a STABLE context block (the fit-check's ~10k-token tool
 * catalog — same across both attempts and across builds minutes apart). On
 * Anthropic it is sent as a system content block marked `cache_control:
 * ephemeral` (same mechanism as {@see ChatAgent}), so the retry and the next
 * build bill it as cached input (~0.1x) instead of re-reading it cold —
 * observed: a Haiku build re-sent 12k catalog tokens with cache_read 0. Other
 * providers get the identical text folded into the instructions (OpenAI-style
 * prefix caching needs no marker; below Anthropic's per-model minimum the
 * marker is a silent no-op).
 */
class ExpressGateAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  Closure(JsonSchema): array<string, mixed>  $schemaFn
     */
    public function __construct(
        private readonly string $gateInstructions,
        private readonly Closure $schemaFn,
        private readonly ?string $cacheableContext = null,
    ) {}

    public function instructions(): string
    {
        return $this->hasCacheableContext()
            ? $this->gateInstructions."\n\n".$this->cacheableContext
            : $this->gateInstructions;
    }

    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        // A gate is one bounded structured question — reasoning only adds cost,
        // latency, and (on DeepSeek) eats the max_tokens budget the JSON needs.
        // Always off.
        $options = ReasoningOptions::forProvider('off', $provider);

        if ($this->hasCacheableContext()
            && ($provider === Lab::Anthropic || $provider === 'anthropic')) {
            // Replaces the gateway's plain `system` string: instructions first,
            // then the stable context carrying the cache marker — Anthropic caches
            // the whole prefix up to and including the marked block.
            $options['system'] = [
                ['type' => 'text', 'text' => $this->gateInstructions],
                ['type' => 'text', 'text' => (string) $this->cacheableContext, 'cache_control' => ['type' => 'ephemeral']],
            ];
        }

        return $options;
    }

    private function hasCacheableContext(): bool
    {
        return $this->cacheableContext !== null && trim($this->cacheableContext) !== '';
    }

    public function schema(JsonSchema $schema): array
    {
        return ($this->schemaFn)($schema);
    }

    /**
     * Output cap. Bounds a runaway reply without strangling reasoning models:
     * on DeepSeek the REASONING tokens count against max_tokens, and a 900
     * cap was observed truncating the fit-check at exactly 900 (reasoning ate
     * the budget, no room left for the JSON) — degrading gates to fallbacks.
     * Observed reasoning burn peaks ~3k; 4000 leaves headroom while still
     * capping a pathological loop. The SDK prefers this method over a
     * MaxTokens attribute.
     */
    public function maxTokens(): int
    {
        return 4000;
    }
}
