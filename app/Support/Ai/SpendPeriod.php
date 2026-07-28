<?php

namespace App\Support\Ai;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * A window for the AI spend dashboards. Two kinds live side by side: rolling
 * windows ("last 7 days") and calendar periods ("this month"), which end at the
 * same instant but start at a boundary rather than N days back — so "this month"
 * on the 3rd is three days, not thirty.
 *
 * The period also picks the bucket the cost series is drawn in: a single day
 * plotted daily is one flat point, so "today" buckets hourly instead.
 */
final class SpendPeriod
{
    public const DEFAULT = '30d';

    /** @var list<int> */
    private const ROLLING_DAYS = [7, 30, 90];

    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly Carbon $since,
        public readonly int $buckets,
        public readonly bool $hourly,
    ) {}

    /**
     * Normalise whatever a caller has: an already-built period, a period key, or
     * a legacy day count (the `days` argument every call site used before named
     * periods existed).
     */
    public static function resolve(self|string|int|null $period): self
    {
        return match (true) {
            $period instanceof self => $period,
            is_int($period) => self::rolling($period),
            is_string($period) => self::fromKey($period),
            default => self::fromKey(self::DEFAULT),
        };
    }

    /**
     * Read the window off a dashboard request. `?period=` is the current form;
     * `?days=` still resolves so existing links and bookmarks keep working.
     */
    public static function fromRequest(Request $request): self
    {
        $key = $request->query('period');

        if (is_string($key) && $key !== '') {
            return self::fromKey($key);
        }

        return self::rolling($request->integer('days') ?: 30);
    }

    /**
     * An unknown key falls back to the default window rather than erroring — the
     * period comes from a query string, so a stale link must not 500.
     */
    public static function fromKey(string $key): self
    {
        $today = Carbon::today();

        return match ($key) {
            'today' => new self('today', 'Today', $today, 24, true),
            'week' => self::calendar('week', 'This week', $today->copy()->startOfWeek()),
            'month' => self::calendar('month', 'This month', $today->copy()->startOfMonth()),
            '7d' => self::rolling(7),
            '90d' => self::rolling(90),
            default => self::rolling(30),
        };
    }

    /**
     * A rolling window ending today. Only the three offered lengths are
     * accepted; anything else is the default.
     */
    public static function rolling(int $days): self
    {
        if (! in_array($days, self::ROLLING_DAYS, true)) {
            $days = 30;
        }

        return new self(
            $days.'d',
            "Last {$days} days",
            Carbon::today()->subDays($days - 1),
            $days,
            false,
        );
    }

    /**
     * The windows offered in the dashboard picker, in display order.
     *
     * @return list<array{key: string, label: string, short: string}>
     */
    public static function options(): array
    {
        return [
            ['key' => 'today', 'label' => 'Today', 'short' => 'Today'],
            ['key' => 'week', 'label' => 'This week', 'short' => 'Week'],
            ['key' => 'month', 'label' => 'This month', 'short' => 'Month'],
            ['key' => '7d', 'label' => 'Last 7 days', 'short' => '7d'],
            ['key' => '30d', 'label' => 'Last 30 days', 'short' => '30d'],
            ['key' => '90d', 'label' => 'Last 90 days', 'short' => '90d'],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_column(self::options(), 'key');
    }

    /**
     * Calendar days the window covers, for the `range_days` field consumers
     * (including the MCP tool) already read.
     */
    public function days(): int
    {
        return $this->hourly ? 1 : $this->buckets;
    }

    public function granularity(): string
    {
        return $this->hourly ? 'hour' : 'day';
    }

    /**
     * The bucket-label projection for a spend query, matching `bucketLabels()`
     * so the series can be zero-filled by string key.
     */
    public function bucketColumn(string $column = 'created_at'): string
    {
        return $this->hourly
            ? "to_char({$column}, 'YYYY-MM-DD HH24:00') as d"
            : "date({$column}) as d";
    }

    /**
     * Every bucket in the window, so the chart has a point per bucket even
     * where nothing was spent.
     *
     * @return list<string>
     */
    public function bucketLabels(): array
    {
        $labels = [];

        for ($i = 0; $i < $this->buckets; $i++) {
            $labels[] = $this->hourly
                ? $this->since->copy()->addHours($i)->format('Y-m-d H:00')
                : $this->since->copy()->addDays($i)->toDateString();
        }

        return $labels;
    }

    /**
     * @return array{key: string, label: string, granularity: string, since: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'granularity' => $this->granularity(),
            'since' => $this->since->toDateTimeString(),
        ];
    }

    private static function calendar(string $key, string $label, Carbon $start): self
    {
        return new self(
            $key,
            $label,
            $start,
            (int) $start->diffInDays(Carbon::today()) + 1,
            false,
        );
    }
}
