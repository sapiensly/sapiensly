<?php

use App\Services\Workflows\DateReachedEvaluator;

beforeEach(function () {
    $this->evaluator = new DateReachedEvaluator;
});

it('returns the exact instant for a datetime field, shifted by the offset', function () {
    $raw = '2026-07-01T12:00:00Z';

    // 3 days before
    expect(
        $this->evaluator->targetInstant($raw, 'datetime', ['value' => 3, 'unit' => 'days', 'direction' => 'before'])
            ->toIso8601String()
    )->toBe('2026-06-28T12:00:00+00:00');

    // 2 hours after
    expect(
        $this->evaluator->targetInstant($raw, 'datetime', ['value' => 2, 'unit' => 'hours', 'direction' => 'after'])
            ->toIso8601String()
    )->toBe('2026-07-01T14:00:00+00:00');

    // no offset → the field instant itself
    expect(
        $this->evaluator->targetInstant($raw, 'datetime', [])->toIso8601String()
    )->toBe('2026-07-01T12:00:00+00:00');
});

it('anchors a date-only field at `at` in the given timezone', function () {
    // 2026-07-01 at 09:00 in Mexico City (UTC-6) → 15:00 UTC
    expect(
        $this->evaluator->targetInstant('2026-07-01', 'date', [], '09:00', 'America/Mexico_City')
            ->toIso8601String()
    )->toBe('2026-07-01T15:00:00+00:00');

    // default at = 09:00 UTC
    expect(
        $this->evaluator->targetInstant('2026-07-01', 'date', [])->toIso8601String()
    )->toBe('2026-07-01T09:00:00+00:00');

    // 1 day before, at 08:00 UTC
    expect(
        $this->evaluator->targetInstant('2026-07-01', 'date', ['value' => 1, 'unit' => 'days', 'direction' => 'before'], '08:00', 'UTC')
            ->toIso8601String()
    )->toBe('2026-06-30T08:00:00+00:00');
});

it('returns null for an empty or unparseable value', function () {
    expect($this->evaluator->targetInstant(null, 'datetime', []))->toBeNull()
        ->and($this->evaluator->targetInstant('', 'date', []))->toBeNull()
        ->and($this->evaluator->targetInstant('not-a-date', 'datetime', []))->toBeNull();
});

it('computes signed offset minutes (before is negative)', function () {
    expect($this->evaluator->offsetMinutes(['value' => 2, 'unit' => 'hours', 'direction' => 'after']))->toBe(120)
        ->and($this->evaluator->offsetMinutes(['value' => 1, 'unit' => 'days', 'direction' => 'before']))->toBe(-1440)
        ->and($this->evaluator->offsetMinutes(['value' => 1, 'unit' => 'weeks', 'direction' => 'after']))->toBe(10080)
        ->and($this->evaluator->offsetMinutes([]))->toBe(0);
});
