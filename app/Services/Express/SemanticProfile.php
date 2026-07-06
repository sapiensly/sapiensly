<?php

namespace App\Services\Express;

use App\Services\Records\InMemoryRowFilter;

/**
 * The semantic layer that keeps dashboard numbers HONEST. Two classifications
 * drive an aggregation-legality matrix:
 *
 * GRAIN of an object's rows —
 *   raw: one row per real-world record (a ticket, an order);
 *   time_series: pre-aggregated rows, one per time bucket;
 *   dimension_breakdown: pre-aggregated rows, one per category value.
 * Re-aggregating pre-aggregated rows as if they were records produced the
 * worst lies observed in production: "Total tickets: 5" (a count of WEEKS),
 * KPIs summing percentages, P95-of-averages.
 *
 * MEASURE TYPE of a numeric field —
 *   additive: counts/amounts — sum is meaningful (total_tickets, monto);
 *   ratio: percentages/rates — sum is ALWAYS wrong, avg is an approximation;
 *   statistic: pre-computed avg/median/percentiles/stddev — re-aggregating is
 *   statistically meaningless; they are shown per row, never folded.
 *
 * Everything here is derived from names + the sampled rows; no model calls.
 */
class SemanticProfile
{
    public const GRAIN_RAW = 'raw';

    public const GRAIN_TIME_SERIES = 'time_series';

    public const GRAIN_DIMENSION = 'dimension_breakdown';

    public const MEASURE_ADDITIVE = 'additive';

    public const MEASURE_RATIO = 'ratio';

    public const MEASURE_STATISTIC = 'statistic';

    private const STATISTIC_NAME = '/(^|_)(avg|average|promedio|mean|median|mediana|p\d{2,3}|min|max|std|desviacion|stddev)(_|$)/i';

    private const RATIO_NAME = '/(pct|percent|porcentaje|rate|ratio|tasa|_share|nps$|csat$|score$)/i';

    private const ADDITIVE_NAME = '/(count|total|cantidad|monto|amount|revenue|ingreso|volumen|tickets?|orders?|unidades|qty)/i';

    /**
     * @param  array<string, mixed>  $object  manifest object node
     * @param  list<array<string, mixed>>  $rows  sampled external rows (may be [])
     */
    public function grainOf(array $object, array $rows = []): string
    {
        $fields = collect($object['fields'] ?? []);
        $slugs = $fields->pluck('slug')->map(fn ($s) => (string) $s);
        $collection = (string) ($object['source']['operations']['list']['collection_path'] ?? '');

        // Bucket-named fields or a series-ish collection → time-bucketed rows.
        if ($slugs->contains(fn (string $s) => preg_match('/^bucket|_bucket|^semana$|^week$|^periodo$/i', $s) === 1)
            || preg_match('/series|daily|weekly|monthly/i', $collection) === 1) {
            return self::GRAIN_TIME_SERIES;
        }

        // A `key`-style label + a breakdown-ish collection → one row per category.
        if ($slugs->contains(fn (string $s) => preg_match('/^key$|^dimension$|^grupo$/i', $s) === 1)
            || preg_match('/breakdown|by_dimension|by_[a-z]+$/i', $collection) === 1) {
            return self::GRAIN_DIMENSION;
        }

        // Pre-computed statistic columns without a date axis smell aggregated
        // even without naming conventions (a sellers comparison table).
        $statisticColumns = $fields
            ->filter(fn (array $f) => in_array($f['type'] ?? '', ['number', 'currency'], true))
            ->filter(fn (array $f) => preg_match(self::STATISTIC_NAME, (string) ($f['slug'] ?? '')) === 1)
            ->count();
        $hasDate = $fields->contains(fn (array $f) => in_array($f['type'] ?? '', ['date', 'datetime'], true));
        if ($statisticColumns >= 2 && ! $hasDate) {
            return self::GRAIN_DIMENSION;
        }

        return self::GRAIN_RAW;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<mixed>  $values  the field's sampled values (may be [])
     */
    public function measureTypeOf(array $field, array $values = []): string
    {
        $slug = (string) ($field['slug'] ?? '');

        if (preg_match(self::STATISTIC_NAME, $slug) === 1) {
            return self::MEASURE_STATISTIC;
        }
        if (preg_match(self::RATIO_NAME, $slug) === 1) {
            return self::MEASURE_RATIO;
        }
        if (preg_match(self::ADDITIVE_NAME, $slug) === 1) {
            return self::MEASURE_ADDITIVE;
        }

        // Nameless hint: every sampled value inside 0..100 with decimals reads
        // like a percentage more than like a count.
        $numeric = array_values(array_filter($values, 'is_numeric'));
        if (count($numeric) >= 3) {
            $inRange = count(array_filter($numeric, fn ($v) => $v >= 0 && $v <= 100));
            $hasDecimals = count(array_filter($numeric, fn ($v) => is_float($v + 0) && floor((float) $v) != $v));
            if ($inRange === count($numeric) && $hasDecimals > 0) {
                return self::MEASURE_RATIO;
            }
        }

        return self::MEASURE_ADDITIVE;
    }

    /**
     * The legality matrix: which KPI aggregations produce a MEANINGFUL number
     * for this measure on this grain. Empty array = the field must not be
     * folded into a KPI at all (statistics are shown per row in charts).
     *
     * @return list<string>
     */
    public function legalKpiAggregations(string $measureType, string $grain): array
    {
        if ($measureType === self::MEASURE_STATISTIC) {
            // A statistic of statistics is noise — except on RAW rows, where
            // the column IS the raw measurement despite its name.
            return $grain === self::GRAIN_RAW ? ['median', 'p90', 'p95', 'avg', 'min', 'max'] : [];
        }

        if ($measureType === self::MEASURE_RATIO) {
            // Never sum a percentage. Averaging across buckets is an unweighted
            // approximation — acceptable, labeled as an average.
            return ['avg', 'min', 'max'];
        }

        // Additive: everything classic is meaningful.
        return $grain === self::GRAIN_RAW
            ? ['count', 'sum', 'avg', 'median', 'p90', 'p95', 'min', 'max']
            : ['sum', 'avg', 'min', 'max'];
    }

    /** count(rows) only means "how many records" on raw grain. */
    public function countIsMeaningful(string $grain): bool
    {
        return $grain === self::GRAIN_RAW;
    }

    /**
     * Column stats the data-aware suggester decides with.
     *
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array{values: list<mixed>, distinct: int, null_rate: float, all_equal: bool}>
     */
    public function columnStats(array $object, array $rows): array
    {
        $pathByFieldId = collect($object['source']['field_map'] ?? [])->pluck('external_path', 'field_id')->all();
        $stats = [];

        foreach ($object['fields'] ?? [] as $field) {
            $path = $pathByFieldId[$field['id']] ?? ($field['slug'] ?? null);
            $raw = $path !== null
                ? array_map(fn (array $r) => data_get($r, $path), $rows)
                : [];
            $values = array_values(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
            $distinct = count(array_unique(array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $values)));

            $stats[$field['id']] = [
                'values' => $values,
                'distinct' => $distinct,
                'null_rate' => $raw === [] ? 0.0 : round((count($raw) - count($values)) / count($raw), 2),
                'all_equal' => count($values) > 1 && $distinct === 1,
            ];
        }

        return $stats;
    }

    /**
     * Distinct day-level buckets a temporal column spans — drives bucket choice
     * and whether a time chart is worth drawing at all.
     *
     * @param  list<mixed>  $values
     */
    public function temporalSpanDays(array $values): int
    {
        $timestamps = array_values(array_filter(array_map(
            fn ($v) => InMemoryRowFilter::timestamp($v),
            $values,
        )));
        if (count($timestamps) < 2) {
            return 0;
        }

        return (int) ceil((max($timestamps) - min($timestamps)) / 86400);
    }
}
