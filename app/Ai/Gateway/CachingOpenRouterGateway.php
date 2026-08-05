<?php

namespace App\Ai\Gateway;

use Illuminate\Support\Arr;
use Laravel\Ai\Gateway\OpenRouter\OpenRouterGateway;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;

/**
 * The stock OpenRouter gateway plus explicit prompt caching for the Anthropic
 * models it brokers.
 *
 * The app assumed OpenRouter cached the stable prefix by itself, the way the
 * OpenAI-compatible providers do. That holds for most of the catalog but NOT
 * for Anthropic: its caching is explicit everywhere, and a request that carries
 * no `cache_control` breakpoint is billed in full no matter who brokers it.
 * Measured live on two builder turns of the SAME brief through the SAME broker:
 * grok read 533k cached tokens, `~anthropic/claude-haiku-latest` read ZERO and
 * cost more than the pricier model it was chosen over.
 *
 * OpenRouter forwards `cache_control` to Anthropic, but only inside a
 * structured content part — the OpenAI-compat `content: "…"` string has nowhere
 * to hang it. So this gateway rewrites the two blocks worth caching into parts
 * and marks them: the SYSTEM message (the builder's frozen prompt + its tool
 * definitions, re-sent every turn) and the LAST message (a moving breakpoint,
 * so each agentic round trip re-reads the previous history at ~0.1x instead of
 * re-billing it whole).
 *
 * Opt-in via {@see self::CACHE_MESSAGES_FLAG} in an agent's providerOptions,
 * and only for Anthropic-backed models — everything else on OpenRouter either
 * caches server-side already or ignores the marker. A request without the flag
 * builds a byte-identical body to the stock gateway.
 */
class CachingOpenRouterGateway extends OpenRouterGateway
{
    /**
     * Opt-in marker an agent's providerOptions() sets. Never reaches the wire.
     *
     * Deliberately the same string {@see CachingAnthropicGateway} uses: an
     * agent asks for conversation caching once and whichever gateway serves the
     * turn honours it. A test pins the two constants together.
     */
    public const CACHE_MESSAGES_FLAG = 'sapiensly_cache_messages';

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

        if (! self::isAnthropicModel($model)) {
            return $body;
        }

        return self::withCacheBreakpoints($body);
    }

    /**
     * Is this OpenRouter model served by Anthropic?
     *
     * OpenRouter ids are `vendor/model`; the platform catalog also stores
     * floating aliases with a `~` prefix (`~anthropic/claude-haiku-latest`),
     * so both spellings have to resolve the same way.
     */
    public static function isAnthropicModel(string $model): bool
    {
        return str_starts_with(ltrim(mb_strtolower(trim($model)), '~'), 'anthropic/');
    }

    /**
     * Mark the system message and the last message as cache breakpoints.
     *
     * Anthropic caches everything BEFORE a breakpoint, so two are enough: the
     * system block covers the frozen prefix, the last message covers whatever
     * the conversation has grown to since.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public static function withCacheBreakpoints(array $body): array
    {
        $messages = $body['messages'] ?? null;

        if (! is_array($messages) || $messages === []) {
            return $body;
        }

        $lastIndex = array_key_last($messages);

        $systemIndex = null;
        foreach ($messages as $i => $message) {
            if (($message['role'] ?? null) === 'system') {
                $systemIndex = $i;
                break;
            }
        }

        foreach (array_unique(array_filter([$systemIndex, $lastIndex], fn ($i) => $i !== null)) as $index) {
            $messages[$index] = self::withBreakpoint($messages[$index]);
        }

        $body['messages'] = $messages;

        return $body;
    }

    /**
     * Put a breakpoint on a message's last text part, promoting a plain string
     * body to the structured form that can carry one.
     *
     * A message with no text part (a bare tool call) is returned untouched —
     * marking a non-text block is rejected by Anthropic.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private static function withBreakpoint(array $message): array
    {
        $content = $message['content'] ?? null;

        if (is_string($content) && trim($content) !== '') {
            $message['content'] = [[
                'type' => 'text',
                'text' => $content,
                'cache_control' => ['type' => 'ephemeral'],
            ]];

            return $message;
        }

        if (! is_array($content) || $content === []) {
            return $message;
        }

        $target = null;
        foreach ($content as $i => $part) {
            if (is_array($part) && ($part['type'] ?? null) === 'text') {
                $target = $i;
            }
        }

        if ($target === null) {
            return $message;
        }

        $content[$target]['cache_control'] = ['type' => 'ephemeral'];
        $message['content'] = $content;

        return $message;
    }
}
