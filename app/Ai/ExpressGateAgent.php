<?php

namespace App\Ai;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * The Express pipeline's gate agent: one bounded structured question, ZERO
 * tools, tiny instructions. This shape — not the model — is what makes a gate
 * fast: no 40k prefill, no tool schemas, no history to re-read, and the SDK
 * enforces the JSON schema on the way out.
 */
class ExpressGateAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  Closure(JsonSchema): array<string, mixed>  $schemaFn
     */
    public function __construct(
        private readonly string $gateInstructions,
        private readonly Closure $schemaFn,
    ) {}

    public function instructions(): string
    {
        return $this->gateInstructions;
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
