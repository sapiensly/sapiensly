<?php

namespace App\Services\Express;

use App\Services\Records\InMemoryRowFilter;

/**
 * Real numbers for the insight gate: aggregates computed over the rows the
 * acquisition phase ALREADY fetched, so the model narrates facts instead of
 * imagining them — the prompt carries "top category: Envíos (34%)", not a
 * request to guess. Everything here is derived; nothing asks a model.
 */
class ComputedFactsBuilder
{
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

        $fields = array_values(array_filter($object['fields'] ?? [], 'is_array'));
        $pathByFieldId = collect($object['source']['field_map'] ?? [])
            ->pluck('external_path', 'field_id')->all();
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
            $peak = $this->weeklyPeak($object, $rows);
            if ($peak !== null) {
                $series[] = ['object' => (string) ($object['name'] ?? $object['slug'] ?? ''), ...$peak];
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

        return $facts;
    }

    /**
     * The ISO week (and value) where the object's primary numeric peaks.
     *
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $rows
     * @return array{metric: string, week: string, value: float|int}|null
     */
    private function weeklyPeak(array $object, array $rows): ?array
    {
        $fields = collect($object['fields'] ?? []);
        $dateField = $fields->first(fn (array $f): bool => in_array($f['type'] ?? '', ['date', 'datetime'], true));
        $numField = $fields->first(fn (array $f): bool => in_array($f['type'] ?? '', ['number', 'currency'], true));
        if ($dateField === null || $numField === null) {
            return null;
        }

        $pathByFieldId = collect($object['source']['field_map'] ?? [])->pluck('external_path', 'field_id')->all();
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

        arsort($byWeek);
        $week = (string) array_key_first($byWeek);

        return [
            'metric' => (string) ($numField['name'] ?? $numField['slug']),
            'week' => $week,
            'value' => round($byWeek[$week], 2),
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
        $pathByFieldId = collect($object['source']['field_map'] ?? [])->pluck('external_path', 'field_id')->all();
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
        $path = collect($object['source']['field_map'] ?? [])
            ->pluck('external_path', 'field_id')[$dateField['id']] ?? ($dateField['slug'] ?? null);
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
}
