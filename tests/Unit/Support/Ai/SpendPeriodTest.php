<?php

use App\Support\Ai\SpendPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The AI spend window. Rolling windows ("last 30 days") and calendar periods
 * ("this month") share one value object, which also decides whether the cost
 * series is bucketed by day or by hour.
 */

// A Wednesday, mid-month, so week/month starts are both distinguishable from
// "N days back" and from each other.
beforeEach(fn () => Carbon::setTestNow('2026-07-15 09:30:00'));
afterEach(fn () => Carbon::setTestNow());

it('resolves a rolling window ending today', function () {
    $period = SpendPeriod::fromKey('7d');

    expect($period->key)->toBe('7d')
        ->and($period->label)->toBe('Last 7 days')
        ->and($period->since->toDateString())->toBe('2026-07-09')
        ->and($period->days())->toBe(7)
        ->and($period->granularity())->toBe('day')
        ->and($period->bucketLabels())->toHaveCount(7);
});

it('buckets today by the hour so a single day is not one flat point', function () {
    $period = SpendPeriod::fromKey('today');

    expect($period->since->toDateTimeString())->toBe('2026-07-15 00:00:00')
        ->and($period->granularity())->toBe('hour')
        ->and($period->days())->toBe(1)
        ->and($period->bucketColumn())->toBe("to_char(created_at, 'YYYY-MM-DD HH24:00') as d")
        ->and($period->bucketLabels())->toHaveCount(24)
        ->and($period->bucketLabels()[0])->toBe('2026-07-15 00:00')
        ->and($period->bucketLabels()[23])->toBe('2026-07-15 23:00');
});

it('starts this week on Monday and runs through today', function () {
    $period = SpendPeriod::fromKey('week');

    // Wednesday the 15th → Monday the 13th, three buckets (13th, 14th, 15th).
    expect($period->since->toDateString())->toBe('2026-07-13')
        ->and($period->days())->toBe(3)
        ->and($period->bucketLabels())->toBe(['2026-07-13', '2026-07-14', '2026-07-15']);
});

it('starts this month on the 1st and runs through today', function () {
    $period = SpendPeriod::fromKey('month');

    expect($period->since->toDateString())->toBe('2026-07-01')
        ->and($period->days())->toBe(15)
        ->and($period->granularity())->toBe('day');
});

it('falls back to the default window for an unknown key', function () {
    // The key comes off a query string, so a stale link must not error.
    expect(SpendPeriod::fromKey('last-decade')->key)->toBe('30d')
        ->and(SpendPeriod::rolling(45)->key)->toBe('30d');
});

it('resolves a legacy day count so old call sites keep working', function () {
    expect(SpendPeriod::resolve(90)->key)->toBe('90d')
        ->and(SpendPeriod::resolve(null)->key)->toBe('30d');

    $period = SpendPeriod::fromKey('7d');
    expect(SpendPeriod::resolve($period))->toBe($period);
});

it('reads period from a request, honouring legacy days links', function () {
    expect(SpendPeriod::fromRequest(Request::create('/system/ai-spend?period=month'))->key)->toBe('month')
        ->and(SpendPeriod::fromRequest(Request::create('/system/ai-spend?days=7'))->key)->toBe('7d')
        ->and(SpendPeriod::fromRequest(Request::create('/system/ai-spend'))->key)->toBe('30d');
});

it('offers every window in the picker and keys match', function () {
    expect(SpendPeriod::keys())->toBe(['today', 'week', 'month', '7d', '30d', '90d']);

    foreach (SpendPeriod::options() as $option) {
        expect(SpendPeriod::fromKey($option['key'])->key)->toBe($option['key']);
    }
});
