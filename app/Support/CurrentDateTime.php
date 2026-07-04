<?php

namespace App\Support;

/**
 * Single source of truth for "the current UTC date/time" surfaced to AI models —
 * both the `current_datetime` tool payload and the line injected into every
 * agent system prompt. A model has no clock (its weights are frozen at a
 * training cutoff), so any time-relative decision (today, "last N days", ages,
 * deadlines, scheduling, date filters) must be grounded in this, never guessed.
 */
class CurrentDateTime
{
    /**
     * The current UTC instant in the shapes a model is most likely to want.
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
     * A one-line system-prompt injection: the current UTC datetime plus the rule
     * that the model must use it (or the tool) for anything time-relative.
     */
    public static function promptLine(): string
    {
        $now = now()->utc();

        return 'CURRENT DATE/TIME — it is '.$now->toIso8601String().' ('.$now->format('l').') in UTC. '
            .'You have no internal clock, so use THIS (or call the `current_datetime` tool to refresh it in a '
            .'long session) for anything time-relative: today, "last N days", ages, deadlines, scheduling, '
            .'date filters. Never guess or assume the current date.';
    }
}
