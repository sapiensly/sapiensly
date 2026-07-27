<?php

namespace App\Support;

use App\Ai\BuilderAgent;
use App\Ai\ChatAgent;
use App\Ai\Gateway\CachingAnthropicGateway;
use App\Ai\RuntimeAgent;
use Carbon\CarbonInterface;
use DateTimeZone;

/**
 * Single source of truth for "the current date/time" surfaced to AI models —
 * both the `current_datetime` tool payload and the line injected into every
 * agent system prompt. A model has no clock (its weights are frozen at a
 * training cutoff), so any time-relative decision (today, "last N days", ages,
 * deadlines, scheduling, date filters) must be grounded in this, never guessed.
 *
 * The system line is deliberately HOUR-GRANULAR. It sits inside the frozen
 * prefix that {@see ChatAgent}, {@see RuntimeAgent} and
 * {@see BuilderAgent} mark as an Anthropic cache breakpoint, and that
 * cache is an exact prefix match: a stamp carrying seconds changes on every
 * turn, so the whole tools+system prefix misses the cache and is re-billed at
 * full rate on each new turn (it still hits across the round trips *within* one
 * agentic turn, where the string is computed once). Worse for the builder,
 * where the request renders tools → system → messages: a volatile system block
 * also invalidates the moving message breakpoint that
 * {@see CachingAnthropicGateway} sets, since that breakpoint's
 * prefix contains the system. Rounding to the hour caps the loss at one miss
 * per hour per conversation while keeping everything a model actually needs —
 * day-level grounding — and the `current_datetime` tool still returns the exact
 * instant on demand.
 */
class CurrentDateTime
{
    /**
     * Granularity of the system-prompt stamp. Anything finer than this is a
     * prompt-cache miss on every turn; see the class docblock before changing
     * it. Lower it to 'Y-m-d' (with a matching truncation below) if the cache
     * ever needs to survive longer than an hour.
     */
    private const STAMP_FORMAT = 'Y-m-d H:i';

    /**
     * The current UTC instant in the shapes a model is most likely to want.
     * Unlike {@see self::systemLine()} this is exact to the second — it is
     * returned by a tool call, never embedded in a cacheable prefix.
     *
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        $now = now()->utc();

        return [
            'utc' => $now->toIso8601String(),
            'date' => $now->toDateString(),
            'time' => $now->format('H:i:s'),
            'day_of_week' => $now->format('l'),
            'unix' => $now->timestamp,
        ];
    }

    /**
     * A one-line system-prompt injection: the current date/time truncated to the
     * hour, plus the rule that the model must use it (or the tool) for anything
     * time-relative. Rendered in the organization's timezone when its Contextbook
     * declares one, else UTC.
     *
     * Stable within the hour by construction — that property is load-bearing for
     * prompt caching and is locked by a test.
     */
    public static function systemLine(?string $timezone = null): string
    {
        $zone = self::resolveTimezone($timezone);
        $now = self::stampAt($zone);

        return 'CURRENT DATE/TIME — it is '.$now->format(self::STAMP_FORMAT)
            .' ('.$now->format('l').') in '.$zone.', truncated to the hour. '
            .'You have no internal clock, so use THIS for anything time-relative: today, "last N days", '
            .'ages, deadlines, scheduling, date filters. Never guess or assume the current date. '
            .'Call the `current_datetime` tool when you need the exact instant (it returns UTC to the second).';
    }

    /**
     * The current instant in the given timezone, truncated to the stamp's
     * granularity.
     */
    private static function stampAt(string $timezone): CarbonInterface
    {
        return now()->setTimezone($timezone)->startOfHour();
    }

    /**
     * An IANA timezone identifier, or 'UTC' when unset or unrecognized. The
     * Contextbook validates on write, but a stale or hand-edited row must never
     * take down every prompt on the platform.
     */
    private static function resolveTimezone(?string $timezone): string
    {
        if ($timezone === null || trim($timezone) === '') {
            return 'UTC';
        }

        $timezone = trim($timezone);

        return in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : 'UTC';
    }
}
