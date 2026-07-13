<?php

namespace App\Services\Express;

use App\Services\Records\FieldPaths;
use App\Services\Records\InMemoryRowFilter;

/**
 * Real numbers for the insight gate: aggregates computed over the rows the
 * acquisition phase ALREADY fetched, so the model narrates facts instead of
 * imagining them — the prompt carries "top category: Envíos (34%)", not a
 * request to guess. Everything here is derived; nothing asks a model.
 */
class ComputedFactsBuilder
{
    /** Points needed before a row-wise correlation is worth stating. */
    private const CORRELATION_MIN_POINTS = 8;

    /** |r| below this is noise, not a relationship. */
    private const CORRELATION_MIN_R = 0.6;

    /**
     * @param  array<string, mixed>  $object  manifest object node
     * @param  list<array<string, mixed>>  $rows  raw external rows (as sampled)
     * @return array<string, mixed>
     */
    public function build(array $object, array $rows, array $previousRows = []): array
    {
        $facts = [
            'object' => $object['name'] ?? $object['slug'] ?? '',
            'row_count' => count($rows),
        ];

        $pop = $this->periodOverPeriod($object, $rows, $previousRows);
        if ($pop !== []) {
            $facts['vs_periodo_anterior'] = $pop;
        }

        // The analytical pack — anomaly, cumulative concentration, trend
        // slope, correlation — all arithmetic over the sampled rows, zero
        // model. Each key is absent when the data can't honestly support it
        // (guards inside).
        foreach ([
            'anomalia' => $this->anomaly($object, $rows),
            'concentracion' => $this->concentration($object, $rows),
            'tendencia' => $this->trendSlope($object, $rows),
            'correlacion' => $this->correlation($object, $rows),
        ] as $key => $fact) {
            if ($fact !== null) {
                $facts[$key] = $fact;
            }
        }

        $fields = array_values(array_filter($object['fields'] ?? [], 'is_array'));
        $pathByFieldId = FieldPaths::forObject($object);
        $valueOf = function (array $row, array $field) use ($pathByFieldId): mixed {
            $path = $pathByFieldId[$field['id']] ?? ($field['slug'] ?? null);

            return $path !== null ? data_get($row, $path) : null;
        };

        foreach ($fields as $field) {
            $type = $field['type'] ?? 'string';
            $name = (string) ($field['name'] ?? $field['slug']);
            // Scalars only: external rows sometimes carry an array under a
            // field inferred as string (tags, nested leftovers) and casting
            // one to string is a fatal in-pipeline error.
            $values = array_values(array_filter(
                array_map(fn (array $r) => $valueOf($r, $field), $rows),
                fn ($v) => is_scalar($v) && $v !== '',
            ));
            if ($values === []) {
                continue;
            }

            if (in_array($type, ['number', 'currency'], true)) {
                $numeric = array_values(array_filter($values, 'is_numeric'));
                if ($numeric !== []) {
                    sort($numeric);
                    $facts['numeric'][$name] = [
                        'sum' => round(array_sum($numeric), 2),
                        'avg' => round(array_sum($numeric) / count($numeric), 2),
                        'median' => $numeric[intdiv(count($numeric), 2)],
                        'min' => $numeric[0],
                        'max' => end($numeric),
                    ];
                }

                continue;
            }

            if ($type === 'boolean') {
                $true = count(array_filter($values, fn ($v) => $v === true || $v === 1 || $v === '1'));
                $facts['rates'][$name] = [
                    'true_count' => $true,
                    'rate_pct' => round($true * 100 / count($values), 1),
                ];

                continue;
            }

            if ($type === 'string') {
                // A bucket LABEL (period_label, bucket_label, semana…) is the
                // time axis in a string costume — its "dominant value" is just
                // the busiest week, which the fallback insight then narrates as
                // «Valor dominante: 2026-W16», meaningless. Never a top-value.
                // Same guard the suggester applies to categoricals/insights.
                if (preg_match('/label|bucket|period|semana|week/i', (string) ($field['slug'] ?? '')) === 1) {
                    continue;
                }

                $counts = array_count_values(array_map(fn ($v) => (string) $v, $values));
                arsort($counts);
                $distinct = count($counts);
                if ($distinct >= 2 && $distinct <= 25) {
                    $top = array_key_first($counts);
                    $facts['top_values'][$name] = [
                        'top' => $top,
                        'count' => $counts[$top],
                        'share_pct' => round($counts[$top] * 100 / count($values), 1),
                        'distinct' => $distinct,
                    ];
                }

                continue;
            }

            if (in_array($type, ['date', 'datetime'], true)) {
                $timestamps = array_values(array_filter(array_map(
                    fn ($v) => InMemoryRowFilter::timestamp($v),
                    $values,
                )));
                if ($timestamps === []) {
                    continue;
                }
                $weekAgo = now()->utc()->subDays(7)->getTimestamp();
                $twoWeeksAgo = now()->utc()->subDays(14)->getTimestamp();
                $lastWeek = count(array_filter($timestamps, fn (int $t) => $t >= $weekAgo));
                $prevWeek = count(array_filter($timestamps, fn (int $t) => $t >= $twoWeeksAgo && $t < $weekAgo));
                $facts['trend'][$name] = [
                    'last_7d' => $lastWeek,
                    'previous_7d' => $prevWeek,
                    'direction' => $lastWeek <=> $prevWeek,
                    'span_from' => date('Y-m-d', min($timestamps)),
                    'span_to' => date('Y-m-d', max($timestamps)),
                ];
            }
        }

        return $facts;
    }

    /**
     * Cross-object facts: when the build acquired SEVERAL objects with a time
     * axis, align their weekly peaks so the insights can tell a JOINED story
     * ("la semana pico de tickets coincide con el NPS más bajo") instead of
     * narrating each object in isolation.
     *
     * @param  list<array<string, mixed>>  $objects
     * @param  array<string, list<array<string, mixed>>>  $rowsByObject
     * @return list<string>
     */
    public function crossFacts(array $objects, array $rowsByObject): array
    {
        $series = [];
        foreach ($objects as $object) {
            $rows = $rowsByObject[$object['id'] ?? ''] ?? [];
            if ($rows === []) {
                continue;
            }
            $weekly = $this->weeklySeries($object, $rows);
            if ($weekly !== null) {
                // Peak from a COPY: byWeek must stay week-ordered — the
                // correlation aligns the two maps by key order, and a
                // value-sorted map correlates any two series at r = 1.
                $byValue = $weekly['byWeek'];
                arsort($byValue);
                $peakWeek = (string) array_key_first($byValue);
                $series[] = [
                    'object' => (string) ($object['name'] ?? $object['slug'] ?? ''),
                    'metric' => $weekly['metric'],
                    'week' => $peakWeek,
                    'value' => round($byValue[$peakWeek], 2),
                    'byWeek' => $weekly['byWeek'],
                ];
            }
        }
        if (count($series) < 2) {
            return [];
        }

        $facts = [];
        foreach ($series as $entry) {
            $facts[] = "Semana pico de {$entry['metric']} en {$entry['object']}: {$entry['week']} ({$entry['value']}).";
        }
        $weeks = collect($series)->pluck('week')->unique();
        if ($weeks->count() === 1) {
            $facts[] = 'Los picos de todas las métricas COINCIDEN en la semana '.$weeks->first().' — un mismo evento parece explicarlos.';
        }

        // Pairwise co-movement over SHARED weeks: strong correlation between
        // two series is a lead worth stating — always as movement, never as
        // cause. One sentence max; the strongest pair wins.
        $best = null;
        for ($i = 0; $i < count($series); $i++) {
            for ($j = $i + 1; $j < count($series); $j++) {
                $shared = array_intersect_key($series[$i]['byWeek'], $series[$j]['byWeek']);
                if (count($shared) < 5) {
                    continue;
                }
                $a = array_values($shared);
                $b = array_values(array_intersect_key($series[$j]['byWeek'], $shared));
                $r = $this->pearson($a, $b);
                if ($r !== null && abs($r) >= 0.7 && ($best === null || abs($r) > abs($best['r']))) {
                    $best = ['i' => $i, 'j' => $j, 'r' => $r, 'n' => count($shared)];
                }
            }
        }
        if ($best !== null) {
            $a = $series[$best['i']];
            $b = $series[$best['j']];
            $rTxt = number_format($best['r'], 2);
            $facts[] = "«{$a['metric']}» ({$a['object']}) y «{$b['metric']}» ({$b['object']}) se mueven "
                .($best['r'] >= 0 ? 'juntas' : 'en sentidos opuestos')
                ." (r = {$rTxt} en {$best['n']} semanas compartidas) — una relación a revisar, no una causa probada.";
        }

        return $facts;
    }

    /**
     * Pearson correlation of two aligned numeric series; null when either is
     * constant (a flat line correlates with nothing).
     *
     * @param  list<float|int>  $a
     * @param  list<float|int>  $b
     */
    /**
     * The strongest linear relationship between two of the object's own
     * measures — the read a single-measure chart can't make ("cuando el tiempo
     * de primera respuesta sube, el CSAT baja"). The pair with the largest |r|
     * wins, and only if the relationship is strong enough to be worth a claim.
     *
     * Row-wise (not bucketed): every row is a point, which is exactly what a
     * scatter draws. Identifiers are excluded — a correlation with an id is an
     * artefact of insertion order, never a finding.
     *
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $rows
     * @return array{x: string, y: string, x_id: string, y_id: string, r: float, n: int, direction: string}|null
     */
    private function correlation(array $object, array $rows): ?array
    {
        $semantics = new SemanticProfile;
        $paths = FieldPaths::forObject($object);
        $measures = [];
        foreach ($object['fields'] ?? [] as $field) {
            if (! is_array($field) || ! in_array($field['type'] ?? '', ['number', 'currency'], true)) {
                continue;
            }
            if ($semantics->measureTypeOf($field) === SemanticProfile::MEASURE_IDENTIFIER) {
                continue;
            }
            $path = $paths[$field['id']] ?? ($field['slug'] ?? null);
            if ($path !== null) {
                $measures[] = ['field' => $field, 'path' => $path];
            }
        }
        if (count($measures) < 2) {
            return null;
        }

        $best = null;
        foreach ($measures as $i => $a) {
            foreach (array_slice($measures, $i + 1) as $b) {
                // Pair the rows where BOTH measures are present — a correlation
                // over misaligned series is meaningless.
                $xs = $ys = [];
                foreach ($rows as $row) {
                    $x = data_get($row, $a['path']);
                    $y = data_get($row, $b['path']);
                    if (is_numeric($x) && is_numeric($y)) {
                        $xs[] = (float) $x;
                        $ys[] = (float) $y;
                    }
                }
                if (count($xs) < self::CORRELATION_MIN_POINTS) {
                    continue;
                }
                $r = $this->pearson($xs, $ys);
                if ($r === null || abs($r) < self::CORRELATION_MIN_R) {
                    continue;
                }
                if ($best === null || abs($r) > abs($best['r'])) {
                    $best = [
                        'x' => (string) ($a['field']['name'] ?? $a['field']['slug']),
                        'y' => (string) ($b['field']['name'] ?? $b['field']['slug']),
                        'x_id' => (string) $a['field']['id'],
                        'y_id' => (string) $b['field']['id'],
                        'r' => round($r, 2),
                        'n' => count($xs),
                        'direction' => $r >= 0 ? 'up' : 'down',
                    ];
                }
            }
        }

        return $best;
    }

    private function pearson(array $a, array $b): ?float
    {
        $n = min(count($a), count($b));
        if ($n < 2) {
            return null;
        }
        $meanA = array_sum($a) / $n;
        $meanB = array_sum($b) / $n;
        $num = $denA = $denB = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $da = $a[$i] - $meanA;
            $db = $b[$i] - $meanB;
            $num += $da * $db;
            $denA += $da ** 2;
            $denB += $db ** 2;
        }
        if ($denA <= 0.0 || $denB <= 0.0) {
            return null;
        }

        return $num / sqrt($denA * $denB);
    }

    /**
     * The object's primary numeric summed per ISO week — the shared series
     * behind the peak fact and the pairwise correlation.
     *
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $rows
     * @return array{metric: string, byWeek: array<string, float>}|null
     */
    private function weeklySeries(array $object, array $rows): ?array
    {
        $fields = collect($object['fields'] ?? []);
        $dateField = $fields->first(fn (array $f): bool => in_array($f['type'] ?? '', ['date', 'datetime'], true));
        $numField = $fields->first(fn (array $f): bool => in_array($f['type'] ?? '', ['number', 'currency'], true));
        if ($dateField === null || $numField === null) {
            return null;
        }

        $pathByFieldId = FieldPaths::forObject($object);
        $datePath = $pathByFieldId[$dateField['id']] ?? $dateField['slug'];
        $numPath = $pathByFieldId[$numField['id']] ?? $numField['slug'];

        $byWeek = [];
        foreach ($rows as $row) {
            $ts = InMemoryRowFilter::timestamp(data_get($row, $datePath));
            $value = data_get($row, $numPath);
            if ($ts === null || ! is_numeric($value)) {
                continue;
            }
            $week = date('o-\WW', $ts);
            $byWeek[$week] = ($byWeek[$week] ?? 0) + (float) $value;
        }
        if (count($byWeek) < 2) {
            return null;
        }

        ksort($byWeek);

        return [
            'metric' => (string) ($numField['name'] ?? $numField['slug']),
            'byWeek' => $byWeek,
        ];
    }

    /**
     * Period-over-period deltas for the leading measures — the fact that turns
     * "Duplicado: 94" into "94, +18% vs periodo anterior". Two bases:
     * previous-window rows when the acquisition sampled them (window-arg
     * tools), else the object's OWN dated rows split at the midpoint of their
     * span (a series carries its history). Additive measures compare SUMS,
     * ratio/statistic measures compare AVERAGES — the only legal folds for
     * each. Empty when neither base exists.
     *
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $previousRows
     * @return array{base: string, measures: array<string, array{actual: float, anterior: float, delta_pct: float, agg: string}>}|array{}
     */
    private function periodOverPeriod(array $object, array $rows, array $previousRows): array
    {
        $base = 'ventana_anterior';
        $current = $rows;

        if ($previousRows === []) {
            [$current, $previousRows] = $this->splitByDateMidpoint($object, $rows);
            if ($previousRows === []) {
                return [];
            }
            $base = 'mitades';
        }

        $semantics = new SemanticProfile;
        $pathByFieldId = FieldPaths::forObject($object);
        $measures = [];

        foreach ($object['fields'] ?? [] as $field) {
            if (! is_array($field) || ! in_array($field['type'] ?? '', ['number', 'currency'], true)) {
                continue;
            }
            $measureType = $semantics->measureTypeOf($field);
            if (! in_array($measureType, [SemanticProfile::MEASURE_ADDITIVE, SemanticProfile::MEASURE_RATIO, SemanticProfile::MEASURE_STATISTIC], true)) {
                continue;
            }
            $agg = $measureType === SemanticProfile::MEASURE_ADDITIVE ? 'sum' : 'avg';
            $path = $pathByFieldId[$field['id']] ?? ($field['slug'] ?? null);
            if ($path === null) {
                continue;
            }

            $fold = function (array $set) use ($path, $agg): ?float {
                $values = array_values(array_filter(
                    array_map(fn (array $r) => data_get($r, $path), $set),
                    'is_numeric',
                ));
                if ($values === []) {
                    return null;
                }

                return $agg === 'sum'
                    ? round(array_sum($values), 2)
                    : round(array_sum($values) / count($values), 2);
            };

            $actual = $fold($current);
            $anterior = $fold($previousRows);
            if ($actual === null || $anterior === null || abs($anterior) < 0.000001) {
                continue;
            }

            $measures[(string) ($field['name'] ?? $field['slug'])] = [
                'actual' => $actual,
                'anterior' => $anterior,
                'delta_pct' => round(($actual - $anterior) / abs($anterior) * 100, 1),
                'agg' => $agg,
            ];
            if (count($measures) >= 3) {
                break;
            }
        }

        return $measures === [] ? [] : ['base' => $base, 'measures' => $measures];
    }

    /**
     * Split dated rows into the recent half and the earlier half of their own
     * span — the self-contained PoP base for a series (no second fetch).
     *
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function splitByDateMidpoint(array $object, array $rows): array
    {
        $dateField = collect($object['fields'] ?? [])->first(
            fn ($f) => is_array($f) && in_array($f['type'] ?? '', ['date', 'datetime'], true),
        );
        if ($dateField === null || count($rows) < 4) {
            return [$rows, []];
        }
        $path = FieldPaths::forObject($object)[$dateField['id']] ?? null;
        if ($path === null) {
            return [$rows, []];
        }

        $stamped = [];
        foreach ($rows as $row) {
            $ts = InMemoryRowFilter::timestamp(data_get($row, $path));
            if ($ts !== null) {
                $stamped[] = ['ts' => $ts, 'row' => $row];
            }
        }
        if (count($stamped) < 4) {
            return [$rows, []];
        }

        $min = min(array_column($stamped, 'ts'));
        $max = max(array_column($stamped, 'ts'));
        if ($max - $min < 2 * 86400) {
            return [$rows, []]; // under two days there is no "previous period"
        }
        $mid = $min + intdiv($max - $min, 2);

        $recent = $earlier = [];
        foreach ($stamped as $s) {
            if ($s['ts'] > $mid) {
                $recent[] = $s['row'];
            } else {
                $earlier[] = $s['row'];
            }
        }

        return ($recent === [] || $earlier === []) ? [$rows, []] : [$recent, $earlier];
    }

    /**
     * The dated (timestamp, value) pairs of the object's LEADING additive
     * measure — the shared input of the anomaly and slope analyses. Null when
     * there is no date axis or no additive measure with enough points.
     *
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $rows
     * @return array{name: string, pairs: list<array{0: int, 1: float}>}|null
     */
    private function datedMeasurePairs(array $object, array $rows, int $minPoints): ?array
    {
        $fields = collect($object['fields'] ?? [])->filter(fn ($f): bool => is_array($f));
        $dateField = $fields->first(fn (array $f): bool => in_array($f['type'] ?? '', ['date', 'datetime'], true));
        if ($dateField === null) {
            return null;
        }

        $semantics = new SemanticProfile;
        $measure = $fields->first(fn (array $f): bool => in_array($f['type'] ?? '', ['number', 'currency'], true)
            && $semantics->measureTypeOf($f) === SemanticProfile::MEASURE_ADDITIVE)
            ?? $fields->first(fn (array $f): bool => in_array($f['type'] ?? '', ['number', 'currency'], true));
        if ($measure === null) {
            return null;
        }

        $pathByFieldId = FieldPaths::forObject($object);
        $datePath = $pathByFieldId[$dateField['id']] ?? ($dateField['slug'] ?? null);
        $numPath = $pathByFieldId[$measure['id']] ?? ($measure['slug'] ?? null);
        if ($datePath === null || $numPath === null) {
            return null;
        }

        $pairs = [];
        foreach ($rows as $row) {
            $ts = InMemoryRowFilter::timestamp(data_get($row, $datePath));
            $value = data_get($row, $numPath);
            if ($ts !== null && is_numeric($value)) {
                $pairs[] = [$ts, (float) $value];
            }
        }
        if (count($pairs) < $minPoints) {
            return null;
        }
        usort($pairs, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return ['name' => (string) ($measure['name'] ?? $measure['slug']), 'pairs' => $pairs];
    }

    /**
     * The single point of the dated leading measure that sits furthest out of
     * pattern — reported only when it clears 2σ over at least 6 points (below
     * that a "σ" is noise wearing a lab coat).
     *
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function anomaly(array $object, array $rows): ?array
    {
        $dated = $this->datedMeasurePairs($object, $rows, 6);
        if ($dated === null) {
            return null;
        }
        $values = array_column($dated['pairs'], 1);
        $mean = array_sum($values) / count($values);
        $variance = array_sum(array_map(fn (float $v): float => ($v - $mean) ** 2, $values)) / count($values);
        $std = sqrt($variance);
        if ($std <= 0.0) {
            return null;
        }

        $peak = null;
        foreach ($dated['pairs'] as [$ts, $value]) {
            $z = ($value - $mean) / $std;
            if ($peak === null || abs($z) > abs($peak['z'])) {
                $peak = ['ts' => $ts, 'valor' => $value, 'z' => $z];
            }
        }
        if ($peak === null || abs($peak['z']) < 2.0) {
            return null;
        }

        return [
            'measure' => $dated['name'],
            'fecha' => date('Y-m-d', $peak['ts']),
            'valor' => round($peak['valor'], 2),
            'z' => round(abs($peak['z']), 1),
            'direccion' => $peak['z'] >= 0 ? 'sobre' : 'bajo',
            'media' => round($mean, 2),
        ];
    }

    /**
     * Cumulative concentration of the leading additive measure over the
     * leading categorical: how FEW categories carry at least half the total —
     * the sentence behind a pareto ("3 de 15 motivos concentran el 47%").
     * Needs 5+ real categories; below that concentration states the obvious.
     *
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function concentration(array $object, array $rows): ?array
    {
        $fields = collect($object['fields'] ?? [])->filter(fn ($f): bool => is_array($f));
        $cat = $fields->first(fn (array $f): bool => ($f['type'] ?? '') === 'string'
            && preg_match('/label|bucket|period|semana|week|comment|descrip|nota|_id$|^id$/i', (string) ($f['slug'] ?? '')) !== 1);
        $semantics = new SemanticProfile;
        $measure = $fields->first(fn (array $f): bool => in_array($f['type'] ?? '', ['number', 'currency'], true)
            && $semantics->measureTypeOf($f) === SemanticProfile::MEASURE_ADDITIVE);
        if ($cat === null || $measure === null) {
            return null;
        }

        $pathByFieldId = FieldPaths::forObject($object);
        $catPath = $pathByFieldId[$cat['id']] ?? ($cat['slug'] ?? null);
        $numPath = $pathByFieldId[$measure['id']] ?? ($measure['slug'] ?? null);

        $sums = [];
        foreach ($rows as $row) {
            $key = data_get($row, $catPath);
            $value = data_get($row, $numPath);
            if (is_scalar($key) && trim((string) $key) !== '' && is_numeric($value)) {
                $sums[(string) $key] = ($sums[(string) $key] ?? 0) + (float) $value;
            }
        }
        $total = array_sum($sums);
        if (count($sums) < 5 || $total <= 0) {
            return null;
        }
        arsort($sums);

        $running = 0.0;
        $leaders = [];
        foreach ($sums as $key => $value) {
            $running += $value;
            $leaders[] = $key;
            if ($running / $total >= 0.5) {
                break;
            }
        }
        if (count($leaders) >= count($sums)) {
            return null; // evenly spread — nothing concentrates
        }

        return [
            'measure' => (string) ($measure['name'] ?? $measure['slug']),
            'dimension' => (string) ($cat['name'] ?? $cat['slug']),
            'lideres' => array_slice($leaders, 0, 4),
            'top' => count($leaders),
            'total_categorias' => count($sums),
            'pct' => round($running * 100 / $total, 1),
        ];
    }

    /**
     * The dated leading measure's linear-fit slope, expressed as %-of-mean per
     * bucket with the cadence read from the median gap ("+4.2%/semana").
     * Reported from 4 points and a ±1%/bucket floor — flatter than that is a
     * flat line, not a trend.
     *
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function trendSlope(array $object, array $rows): ?array
    {
        $dated = $this->datedMeasurePairs($object, $rows, 4);
        if ($dated === null) {
            return null;
        }
        $values = array_column($dated['pairs'], 1);
        $n = count($values);
        $mean = array_sum($values) / $n;
        if (abs($mean) < 0.000001) {
            return null;
        }

        // Least squares over (index, value).
        $xMean = ($n - 1) / 2;
        $num = $den = 0.0;
        foreach ($values as $i => $v) {
            $num += ($i - $xMean) * ($v - $mean);
            $den += ($i - $xMean) ** 2;
        }
        if ($den <= 0.0) {
            return null;
        }
        $slopePct = round(($num / $den) / abs($mean) * 100, 1);
        if (abs($slopePct) < 1.0) {
            return null;
        }

        $stamps = array_column($dated['pairs'], 0);
        $gaps = [];
        for ($i = 1; $i < count($stamps); $i++) {
            $gaps[] = $stamps[$i] - $stamps[$i - 1];
        }
        sort($gaps);
        $medianGapDays = (int) round(($gaps[intdiv(count($gaps), 2)] ?? 86400) / 86400);
        $cadencia = match (true) {
            $medianGapDays <= 1 => 'día',
            $medianGapDays <= 10 => 'semana',
            $medianGapDays <= 45 => 'mes',
            default => 'periodo',
        };

        return ['measure' => $dated['name'], 'pendiente_pct' => $slopePct, 'cadencia' => $cadencia];
    }
}
