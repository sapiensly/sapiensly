<?php

namespace App\Services\Express;

use App\Services\Records\FieldPaths;
use App\Services\Records\InMemoryRowFilter;
use Illuminate\Support\Str;

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

    public const MEASURE_IDENTIFIER = 'identifier';

    private const STATISTIC_NAME = '/(^|_)(avg|average|promedio|mean|median|mediana|p\d{2,3}|min|max|std|desviacion|stddev)(_|$)/i';

    private const RATIO_NAME = '/(pct|percent|porcentaje|rate|ratio|tasa|_share|por_100|per_100|por_cada|_x100|nps$|csat$|ces$|score$)/i';

    private const IDENTIFIER_NAME = '/(^|_)id$|^id(_|$)|_id_|folio|codigo|(^|_)code$|telefono|phone/i';

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

        // The object's own identity — slug, name and the tool it reads — often
        // says "breakdown" outright where the collection path does not
        // (tickets_reason_cause_breakdown listed a collection called `reasons`,
        // classified RAW, and shipped an hbar counting one row per reason).
        $identity = Str::lower(implode(' ', [
            (string) ($object['slug'] ?? ''),
            (string) ($object['name'] ?? ''),
            (string) ($object['source']['operations']['list']['mcp_tool'] ?? ''),
        ]));

        // A `key`-style label + a breakdown-ish collection/identity → one row
        // per category.
        if ($slugs->contains(fn (string $s) => preg_match('/^key$|^dimension$|^grupo$/i', $s) === 1)
            || preg_match('/breakdown|by_dimension|by_[a-z]+$/i', $collection) === 1
            || preg_match('/breakdown|desglose|distribution|distribucion|by_dimension/i', $identity) === 1) {
            return self::GRAIN_DIMENSION;
        }

        $hasDate = $fields->contains(fn (array $f) => in_array($f['type'] ?? '', ['date', 'datetime'], true));

        // A share-of-total column only exists on rows that ARE the aggregation
        // (each row a category with its % of the whole) — dateless, that is a
        // dimension breakdown whatever the names say.
        if (! $hasDate && $slugs->contains(
            fn (string $s) => preg_match('/pct_of_total|share_of_total|porcentaje_(del_)?total|_share$/i', $s) === 1,
        )) {
            return self::GRAIN_DIMENSION;
        }

        // Pre-computed statistic columns without a date axis smell aggregated
        // even without naming conventions (a sellers comparison table).
        $statisticColumns = $fields
            ->filter(fn (array $f) => in_array($f['type'] ?? '', ['number', 'currency'], true))
            ->filter(fn (array $f) => preg_match(self::STATISTIC_NAME, (string) ($f['slug'] ?? '')) === 1)
            ->count();
        if ($statisticColumns >= 2 && ! $hasDate) {
            return self::GRAIN_DIMENSION;
        }

        return self::GRAIN_RAW;
    }

    /**
     * A field's measure type, read against the values it actually holds.
     *
     * The value-based fallback in {@see measureTypeOf} could never fire, because
     * every caller in the analytic path passed a field with no values — so any
     * numeric column whose slug missed the regexes was silently ADDITIVE, and got
     * summed. This is the call that gives it what it needs, and the one every
     * caller should use: two callers typing the same column differently is worse
     * than either being wrong.
     *
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $field
     */
    public function measureTypeIn(array $object, array $rows, array $field): string
    {
        $path = FieldPaths::forObject($object)[$field['id'] ?? ''] ?? ($field['slug'] ?? null);
        $values = $path === null ? [] : array_values(array_filter(
            array_map(fn (array $row) => data_get($row, $path), $rows),
            'is_numeric',
        ));

        return $this->measureTypeOf($field, $values);
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<mixed>  $values  the field's sampled values (may be [])
     */
    public function measureTypeOf(array $field, array $values = []): string
    {
        // Read the NAME as well as the slug. A column called «Tasa de reapertura»
        // whose slug is `col_7` is a rate — typing it off the slug alone made it
        // additive, which is how a percentage ends up being summed. The two are
        // tested SEPARATELY (several patterns anchor to the end of the string, so
        // concatenating them would break what the slug already matched), and the
        // strictest reading wins: wrongly summing a rate prints a false number,
        // while wrongly refusing to sum merely withholds a true one.
        $slug = (string) ($field['slug'] ?? '');
        $name = Str::lower(Str::ascii((string) ($field['name'] ?? '')));
        $name = trim(preg_replace('/[^a-z0-9]+/', '_', $name) ?? '', '_');

        $reads = function (string $pattern) use ($slug, $name): bool {
            return preg_match($pattern, $slug) === 1
                || ($name !== '' && preg_match($pattern, $name) === 1);
        };

        // A numeric id is a LABEL wearing a number costume — summing contact
        // ids produced a straight-faced "Suma Id" KPI in production. No
        // aggregation of an identifier means anything.
        if ($reads(self::IDENTIFIER_NAME)) {
            return self::MEASURE_IDENTIFIER;
        }

        if ($reads(self::STATISTIC_NAME)) {
            return self::MEASURE_STATISTIC;
        }
        if ($reads(self::RATIO_NAME)) {
            return self::MEASURE_RATIO;
        }
        if ($reads(self::ADDITIVE_NAME)) {
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
        if ($measureType === self::MEASURE_IDENTIFIER) {
            return []; // ids never aggregate — on any grain
        }

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
     * How a ratio field is SCALED — the difference between an honest "96.7%"
     * and a 9670% lie. The percentage display format multiplies by 100, so it
     * is only correct for fractions:
     *   'fraction' → every sampled value in 0..1 (0.967 = 96.7%);
     *   'percent'  → values in 0..100 (96.7 already IS the percent — display
     *                as a plain number, put the % in the caption);
     *   null       → not a ratio, or the scale can't be told (NPS -100..100,
     *                unbounded ratios, no samples).
     *
     * @param  array<string, mixed>  $field
     * @param  list<mixed>  $values
     */
    public function percentScale(array $field, array $values = []): ?string
    {
        if ($this->measureTypeOf($field, $values) !== self::MEASURE_RATIO) {
            return null;
        }

        $numeric = array_values(array_filter($values, 'is_numeric'));
        if (count($numeric) < 3) {
            return null; // too few samples to trust either reading
        }

        if (count(array_filter($numeric, fn ($v) => $v >= 0 && $v <= 1)) === count($numeric)) {
            return 'fraction';
        }
        if (count(array_filter($numeric, fn ($v) => $v >= 0 && $v <= 100)) === count($numeric)) {
            return 'percent';
        }

        return null;
    }

    /**
     * The unit a field measures in, read from its name — ridden along on KPI
     * captions ("mediana del periodo · min") so a bare number says what it is.
     *
     * @param  array<string, mixed>  $field
     */
    public function unitOf(array $field): ?string
    {
        if (($field['type'] ?? '') === 'currency') {
            return null; // the currency display format already says it
        }
        $slug = (string) ($field['slug'] ?? '');

        return match (true) {
            preg_match('/minut|(^|_)mins?($|_)/i', $slug) === 1 => 'min',
            preg_match('/hora|hour|(^|_)hrs?($|_)/i', $slug) === 1 => 'h',
            preg_match('/dias|days/i', $slug) === 1 => 'días',
            // Percent-NAMED fields carry their values on the 0-100 scale, so
            // the number displays plain and the % rides the caption. (When the
            // values are 0..1 fractions the caller uses the percentage display
            // format instead and skips this unit.)
            preg_match('/pct|percent|porcentaje/i', $slug) === 1 => '%',
            default => null,
        };
    }

    /**
     * Which direction of change is GOOD for this measure — drives the KPI
     * delta chip's colour. Backlogs, reopens, waits and durations should fall;
     * revenue, satisfaction scores and containment should rise. Null when the
     * measure is neutral volume (a bigger ticket count is neither good nor
     * bad) — the chip then renders directionless.
     *
     * @param  array<string, mixed>  $field
     */
    public function deltaGoodOf(array $field): ?string
    {
        $slug = (string) ($field['slug'] ?? '');

        if (preg_match('/backlog|reopen|pendiente|error|fail|queja|devol|churn|espera|demora|abandono|resol|respuesta|minut|hora|(^|_)dur/i', $slug) === 1) {
            return 'down';
        }
        if (preg_match('/venta|revenue|ingreso|nps|csat|resuelt|containment|within|complet|satisf|conversion/i', $slug) === 1) {
            return 'up';
        }

        return null;
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
