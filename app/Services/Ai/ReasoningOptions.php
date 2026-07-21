<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;

/**
 * Translates a reasoning preference ('off'|'low'|'medium'|'high') into the
 * provider-specific request-body fragment that applies it, merged into a chat
 * request via the SDK's HasProviderOptions hook (or the OpenRouter HTTP client).
 *
 * The platform default is OFF: reasoning-capable models otherwise "think" before
 * even trivial replies, paying tokens and latency for nothing. Only OpenRouter
 * and OpenAI need an active field to disable it; Anthropic does not reason unless
 * extended thinking is explicitly enabled, so 'off' is already its default and a
 * no-op here (enabling Anthropic thinking needs max_tokens coordination and is
 * intentionally out of scope for this hook).
 */
class ReasoningOptions
{
    /** Reasoning modes a caller may request (plus null/'default' = leave the model's own). */
    public const MODES = ['off', 'low', 'medium', 'high'];

    /**
     * Model-id patterns (Str::is) whose endpoints reason unconditionally and
     * REJECT an explicit disable outright — OpenRouter 400s "Reasoning is
     * mandatory for this endpoint and cannot be disabled" — rather than
     * ignoring it. For these, 'off' sends nothing and the provider's default
     * wins. Wildcards cover prefixed/suffixed variants (aliases, ':nitro', …).
     * {@see OpenRouterClient::reasoningRejected} is the runtime safety net for
     * models this list doesn't know yet.
     */
    public const MANDATORY_REASONING_MODELS = [
        '*claude-fable-5*',
        '*claude-mythos-5*',
        // OpenAI o-series are reasoning-only models.
        'o1*', 'o3*', 'o4*',
        '*/o1*', '*/o3*', '*/o4*',
    ];

    /** Whether the model reasons unconditionally and rejects an explicit disable. */
    public static function reasoningIsMandatory(?string $model): bool
    {
        return $model !== null && Str::is(self::MANDATORY_REASONING_MODELS, $model);
    }

    /**
     * The request-body fragment to apply $mode for $provider. Empty for a
     * null/'default'/unknown mode, a provider with no reachable control, or an
     * 'off' aimed at a model whose endpoint mandates reasoning (sending the
     * disable would 400 the whole request).
     *
     * @return array<string, mixed>
     */
    public static function forProvider(?string $mode, Lab|string $provider, ?string $model = null): array
    {
        if ($mode === null || $mode === 'default' || ! in_array($mode, self::MODES, true)) {
            return [];
        }

        if ($mode === 'off' && self::reasoningIsMandatory($model)) {
            return [];
        }

        $driver = $provider instanceof Lab ? $provider->value : (string) $provider;

        return match ($driver) {
            'openrouter' => $mode === 'off'
                ? ['reasoning' => ['enabled' => false]]
                : ['reasoning' => ['effort' => $mode]],
            // gpt-5.x reason by default; 'minimal' is the floor that disables it.
            'openai' => ['reasoning_effort' => $mode === 'off' ? 'minimal' : $mode],
            // Anthropic: off is the default (no thinking); effort is not toggled here.
            default => [],
        };
    }
}
