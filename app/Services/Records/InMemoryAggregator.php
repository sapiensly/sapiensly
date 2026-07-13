<?php

namespace App\Services\Records;

use InvalidArgumentException;

/**
 * Folds already-fetched rows (the unified {id, data} shape) into an aggregate,
 * mirroring RecordQueryService's SQL path for objects that have no SQL store to
 * GROUP BY — i.e. connected objects read live from an external integration. One
 * source of truth so the in-memory and SQL paths agree on every aggregation,
 * including distinct_count and the median/p90/p95 percentiles.
 */
class InMemoryAggregator
{
    /** Percentile aggregations → their fraction for the interpolated percentile. */
    private const PERCENTILES = ['median' => 0.5, 'p90' => 0.9, 'p95' => 0.95];

    /**
     * @param  list<array{id: mixed, data: array<string, mixed>}>  $rows
     */
    public function aggregate(array $rows, string $aggregation, ?string $fieldSlug): int|float
    {
        if ($aggregation === 'count') {
            return count($rows);
        }

        $raw = [];
        foreach ($rows as $row) {
            $value = $fieldSlug !== null ? ($row['data'][$fieldSlug] ?? null) : null;
            if ($value !== null && $value !== '') {
                $raw[] = $value;
            }
        }

        return $this->fold($aggregation, $raw);
    }

    /**
     * Group rows by a field (optionally date-bucketed) and fold each bucket,
     * matching RecordQueryService::groupedAggregate's [{group, value}] shape.
     *
     * @param  list<array{id: mixed, data: array<string, mixed>}>  $rows
     * @return list<array{group: mixed, value: int|float}>
     */
    public function grouped(array $rows, string $aggregation, ?string $fieldSlug, string $groupSlug, ?string $bucket, int $limit = 100, ?string $secondGroupSlug = null, ?string $secondBucket = null): array
    {
        // A 2D pivot keys each bucket by both group values; the composite key is
        // split back out into {group, group2} on the way out.
        $sep = "\x1f"; // unit separator — won't collide with real values
        $buckets = [];
        foreach ($rows as $row) {
            $key = $this->bucketKey((string) ($row['data'][$groupSlug] ?? ''), $bucket);
            if ($secondGroupSlug !== null) {
                // The COLUMNS of a cohort table are a date too, and a raw
                // timestamp per column is not a table anyone can read.
                $key .= $sep.$this->bucketKey((string) ($row['data'][$secondGroupSlug] ?? ''), $secondBucket);
            }
            if ($aggregation === 'count') {
                $buckets[$key][] = 1;

                continue;
            }
            $value = $fieldSlug !== null ? ($row['data'][$fieldSlug] ?? null) : null;
            if ($value !== null && $value !== '') {
                $buckets[$key][] = $value;
            }
        }

        ksort($buckets);
        $out = [];
        foreach ($buckets as $key => $values) {
            $value = $aggregation === 'count' ? count($values) : $this->fold($aggregation, $values);
            if ($secondGroupSlug !== null) {
                [$g1, $g2] = explode($sep, (string) $key, 2) + ['', ''];
                $out[] = ['group' => $g1 === '' ? null : $g1, 'group2' => $g2 === '' ? null : $g2, 'value' => $value];
            } else {
                $out[] = ['group' => $key === '' ? null : $key, 'value' => $value];
            }
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Fold a list of raw values for one aggregation. distinct_count counts unique
     * values of any type; the numeric aggregations fold the numeric subset and
     * return 0 when none are numeric.
     *
     * @param  list<mixed>  $raw  non-null, non-empty-string values
     */
    private function fold(string $aggregation, array $raw): int|float
    {
        if ($aggregation === 'distinct_count') {
            return count(array_unique(array_map(
                fn ($v) => is_scalar($v) ? (string) $v : json_encode($v),
                $raw,
            )));
        }

        $values = [];
        foreach ($raw as $v) {
            if (is_numeric($v)) {
                $values[] = $v + 0;
            }
        }
        if ($values === []) {
            return 0;
        }

        if (isset(self::PERCENTILES[$aggregation])) {
            return $this->percentile($values, self::PERCENTILES[$aggregation]);
        }

        return match ($aggregation) {
            'sum' => array_sum($values),
            'avg' => array_sum($values) / count($values),
            'min' => min($values),
            'max' => max($values),
            default => throw new InvalidArgumentException("Unknown aggregation '{$aggregation}'."),
        };
    }

    /**
     * Linear-interpolated percentile, matching Postgres percentile_cont so the
     * in-memory path agrees with the SQL path.
     *
     * @param  list<int|float>  $values
     */
    private function percentile(array $values, float $fraction): float
    {
        sort($values);
        $n = count($values);
        if ($n === 1) {
            return (float) $values[0];
        }

        $rank = $fraction * ($n - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);
        if ($low === $high) {
            return (float) $values[$low];
        }

        return $values[$low] + ($rank - $low) * ($values[$high] - $values[$low]);
    }

    /**
     * Apply day/week/month/quarter/year truncation to a date-ish group value,
     * matching the SQL date_trunc buckets. Returns the raw value unchanged when
     * no bucket is requested or the value is not parseable.
     */
    private function bucketKey(string $raw, ?string $bucket): string
    {
        if ($bucket === null || $raw === '') {
            return $raw;
        }

        $ts = strtotime($raw);
        if ($ts === false) {
            return $raw;
        }

        return match ($bucket) {
            'day' => date('Y-m-d', $ts),
            'week' => date('o-\WW', $ts),
            'month' => date('Y-m', $ts),
            'quarter' => date('Y', $ts).'-Q'.(int) ceil((int) date('n', $ts) / 3),
            'year' => date('Y', $ts),
            default => $raw,
        };
    }
}
