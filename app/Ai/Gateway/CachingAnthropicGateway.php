<?php

namespace App\Ai\Gateway;

use Illuminate\Support\Arr;
use Laravel\Ai\Gateway\Anthropic\AnthropicGateway;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;

/**
 * The stock Anthropic gateway plus incremental prompt caching of the
 * CONVERSATION, not just the system prompt.
 *
 * Anthropic caching is explicit (a `cache_control` breakpoint on a content
 * block caches everything before it) and the SDK offers no message-level hook —
 * agents can only replace top-level body keys via providerOptions. So the only
 * breakpoint the app could set was the system block, and on a long agentic turn
 * that leaves the entire growing message history re-billed at the full input
 * rate on every round trip (measured live: a 27-round-trip landing build paid
 * 519k tokens full-rate vs 684k cached — the history was ~60% of the bill).
 *
 * This subclass adds a MOVING breakpoint: when an agent's providerOptions carry
 * {@see self::CACHE_MESSAGES_FLAG}, the flag is stripped from the body and the
 * last cacheable content block of the last message is marked `cache_control:
 * ephemeral`. Each round trip then re-reads the previous prefix at ~0.1x and
 * writes only the new suffix — the same incremental pattern the OpenAI-compat
 * gateways get from their providers' automatic caching. Registered for the
 * `anthropic` driver in AppServiceProvider; requests without the flag build
 * byte-identical bodies to the stock gateway.
 */
class CachingAnthropicGateway extends AnthropicGateway
{
    /**
     * Opt-in marker an agent's providerOptions() sets to request the moving
     * breakpoint. Never reaches the wire — pulled out of the body here.
     */
    public const CACHE_MESSAGES_FLAG = 'sapiensly_cache_messages';

    /** Block types that legally carry cache_control (thinking blocks cannot). */
    private const CACHEABLE_BLOCK_TYPES = ['text', 'tool_use', 'tool_result', 'image', 'document'];

    protected function buildTextRequestBody(
        Provider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
    ): array {
        $body = parent::buildTextRequestBody($provider, $model, $instructions, $messages, $tools, $schema, $options);

        if (Arr::pull($body, self::CACHE_MESSAGES_FLAG) !== true) {
            return $body;
        }

        return self::withMovingCacheBreakpoint($body);
    }

    /**
     * Mark the last cacheable content block of the last message as a cache
     * breakpoint. Walks backwards past non-cacheable blocks; leaves the body
     * untouched when there is nothing to mark.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public static function withMovingCacheBreakpoint(array $body): array
    {
        $messages = $body['messages'] ?? [];
        $last = array_key_last($messages);
        if ($last === null) {
            return $body;
        }

        $content = $messages[$last]['content'] ?? null;

        if (is_string($content) && trim($content) !== '') {
            $body['messages'][$last]['content'] = [[
                'type' => 'text',
                'text' => $content,
                'cache_control' => ['type' => 'ephemeral'],
            ]];

            return $body;
        }

        if (! is_array($content)) {
            return $body;
        }

        for ($i = count($content) - 1; $i >= 0; $i--) {
            if (is_array($content[$i]) && in_array($content[$i]['type'] ?? null, self::CACHEABLE_BLOCK_TYPES, true)) {
                $body['messages'][$last]['content'][$i]['cache_control'] = ['type' => 'ephemeral'];
                break;
            }
        }

        return $body;
    }
}
