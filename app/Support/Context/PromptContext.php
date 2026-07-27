<?php

namespace App\Support\Context;

use App\Support\CurrentDateTime;

/**
 * What a prompt-building chokepoint needs from the organization Contextbook,
 * resolved once: the rendered block to prepend, and the organization's timezone
 * so {@see CurrentDateTime::systemLine()} can ground the model in
 * local time rather than UTC.
 *
 * Both are null for a personal account, an organization without a Contextbook,
 * or one that switched injection off — in which case the prompt is byte-identical
 * to a platform without this feature.
 */
final class PromptContext
{
    public function __construct(
        public readonly ?string $block = null,
        public readonly ?string $timezone = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    /**
     * Prepend the block to a system prompt. The Contextbook is background
     * knowledge, so it goes FIRST and the agent's own instructions follow — they
     * win by specificity, and the block says so itself. Both parts are stable
     * across turns, which keeps the cached prompt prefix intact.
     */
    public function prepend(string $instructions): string
    {
        return $this->block === null ? $instructions : $this->block."\n\n".$instructions;
    }
}
