<?php

namespace App\Services\Workflows;

use Carbon\CarbonImmutable;

/**
 * Pure date math for the `record.date_reached` trigger: given a record's date
 * field value and the trigger's offset, compute the exact UTC instant the
 * trigger should fire ("the target moment"). Kept dependency-free so it's
 * unit-testable and reused by both the sweep command and its window math.
 *
 *   - datetime fields are stored as ISO UTC → the instant is exact.
 *   - date fields (YYYY-MM-DD) have no time, so the instant is that date at
 *     `at` (HH:MM) in `timezone`, converted to UTC.
 *   - offset shifts the instant `value` `unit`s before/after the field date.
 */
class DateReachedEvaluator
{
    private const UNIT_MINUTES = [
        'minutes' => 1,
        'hours' => 60,
        'days' => 1440,
        'weeks' => 10080,
    ];

    /**
     * @param  array<string, mixed>  $offset  {value, unit, direction}
     */
    public function offsetMinutes(array $offset): int
    {
        $value = (int) ($offset['value'] ?? 0);
        $unit = (string) ($offset['unit'] ?? 'days');
        $minutes = $value * (self::UNIT_MINUTES[$unit] ?? self::UNIT_MINUTES['days']);

        return ($offset['direction'] ?? 'after') === 'before' ? -$minutes : $minutes;
    }

    /**
     * The UTC instant the trigger fires for a record, or null when the field
     * value is empty/unparseable.
     *
     * @param  array<string, mixed>  $offset
     */
    public function targetInstant(
        mixed $raw,
        string $fieldType,
        array $offset,
        string $at = '09:00',
        string $timezone = 'UTC',
    ): ?CarbonImmutable {
        $base = $this->baseInstant($raw, $fieldType, $at, $timezone);
        if ($base === null) {
            return null;
        }

        return $base->addMinutes($this->offsetMinutes($offset));
    }

    private function baseInstant(mixed $raw, string $fieldType, string $at, string $timezone): ?CarbonImmutable
    {
        if (! is_string($raw) && ! is_int($raw)) {
            return null;
        }
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }

        try {
            if ($fieldType === 'date') {
                // No time component — anchor at `at` in the trigger's timezone.
                $at = preg_match('/^\d{1,2}:\d{2}$/', $at) === 1 ? $at : '09:00';

                return CarbonImmutable::parse($value.' '.$at, $timezone)->utc();
            }

            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
