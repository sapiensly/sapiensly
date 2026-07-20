<?php

namespace App\Services\Ai;

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
     * The request-body fragment to apply $mode for $provider. Empty for a
     * null/'default'/unknown mode, or a provider with no reachable control.
     *
     * @return array<string, mixed>
     */
    public static function forProvider(?string $mode, Lab|string $provider): array
    {
        if ($mode === null || $mode === 'default' || ! in_array($mode, self::MODES, true)) {
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
