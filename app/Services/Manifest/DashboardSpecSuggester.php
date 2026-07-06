<?php

namespace App\Services\Manifest;

use App\Services\Express\SemanticProfile;
use Illuminate\Support\Str;

/**
 * Derives a COMPLETE add_dashboard_page spec (KPIs, charts, insight scaffolds,
 * the date field) from an object's schema AND its sampled rows — optimization
 * L2 plus the Q1/Q2 quality layer. Two guarantees the schema-only version
 * could not make:
 *
 *  - Numbers MEAN something: grain + measure-type classification (via
 *    SemanticProfile) drives an aggregation-legality matrix, so a
 *    pre-aggregated weekly series never gets a count(rows) "total" (that
 *    counts WEEKS), percentages are never summed, and pre-computed statistics
 *    (avg_/p50_) are shown per dimension, never re-aggregated.
 *  - Charts FIT the data: cardinality picks the breakdown form (donut 2-8,
 *    hbar 9+ with a limit), mostly-null or constant columns are skipped, and
 *    a time chart only exists when the data spans enough buckets to draw.
 *
 * Deterministic and side-effect free on purpose: prepare_dashboard (to show
 * it) and add_dashboard_page (to apply it) recompute it identically. Without
 * rows it degrades to name-based semantics — still lie-free, less informed.
 */
class DashboardSpecSuggester
{
    private const MAX_KPIS = 6;

    private const MAX_CHARTS = 7;

    private const BREAKDOWN_LIMIT = 12;

    public function __construct(private readonly SemanticProfile $semantics = new SemanticProfile) {}

    /**
     * @param  array<string, mixed>  $object  manifest object_definition
     * @param  list<array<string, mixed>>  $rows  sampled external rows (optional but recommended)
     * @return array<string, mixed> spec in add_dashboard_page's input shape
     */
    public function suggest(array $object, string $lang = 'es', array $rows = []): array
    {
        $es = $lang !== 'en';
        $fields = array_values(array_filter($object['fields'] ?? [], 'is_array'));

        $grain = $this->semantics->grainOf($object, $rows);
        $stats = $rows !== [] ? $this->semantics->columnStats($object, $rows) : [];

        $usable = fn (array $f): bool => $stats === []
            || (($stats[$f['id']]['null_rate'] ?? 0) <= 0.6 && ! ($stats[$f['id']]['all_equal'] ?? false));

        $dateField = $this->pickDateField($fields);
        $categoricals = array_values(array_filter($this->categoricalFields($fields), $usable));
        $numerics = array_values(array_filter(
            $fields,
            fn (array $f): bool => in_array($f['type'] ?? '', ['number', 'currency'], true) && $usable($f),
        ));
        $booleans = array_values(array_filter(
            $fields,
            fn (array $f): bool => ($f['type'] ?? '') === 'boolean' && $usable($f),
        ));

        $measureTypes = [];
        foreach ($numerics as $field) {
            $measureTypes[$field['id']] = $this->semantics->measureTypeOf($field, $stats[$field['id']]['values'] ?? []);
        }

        return array_filter([
            'object_slug' => $object['slug'] ?? null,
            'title' => ($es ? 'Análisis de ' : 'Analysis of ').($object['name'] ?? $object['slug'] ?? ''),
            'date_field_id' => $dateField['id'] ?? null,
            'kpis' => $this->suggestKpis($object, $grain, $numerics, $measureTypes, $booleans, $es),
            'charts' => $this->suggestCharts($grain, $dateField, $categoricals, $numerics, $measureTypes, $stats, $es),
            'insights' => $this->suggestInsights($object, $categoricals, $booleans, $es),
        ], fn ($v) => $v !== null);
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>|null
     */
    private function pickDateField(array $fields): ?array
    {
        $temporal = array_values(array_filter($fields, fn (array $f): bool => in_array($f['type'] ?? '', ['datetime', 'date'], true)));
        if ($temporal === []) {
            return null;
        }

        foreach ($temporal as $field) {
            if (preg_match('/creat|fecha|date|week|semana|bucket|time/i', (string) ($field['slug'] ?? '')) === 1) {
                return $field;
            }
        }

        return $temporal[0];
    }

    /**
     * Category candidates: selects always; strings that don't look like ids,
     * names or free text. When the strict filter leaves nothing, any string
     * counts (in aggregate rows the name IS the category).
     *
     * @param  list<array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    private function categoricalFields(array $fields): array
    {
        $strict = array_values(array_filter($fields, function (array $f): bool {
            $type = $f['type'] ?? '';
            if ($type === 'single_select') {
                return true;
            }
            if ($type !== 'string') {
                return false;
            }

            return preg_match('/id$|folio|number|codigo|code|email|phone|tel|url|nombre$|name$|title|titulo|descri|comment|nota|body/i', (string) ($f['slug'] ?? '')) !== 1;
        }));
        if ($strict !== []) {
            return $strict;
        }

        return array_values(array_filter(
            $fields,
            fn (array $f): bool => ($f['type'] ?? '') === 'string',
        ));
    }

    /**
     * KPIs whose numbers are legal for this grain: count(rows) only when a row
     * IS a record; pre-aggregated grains lead with the SUM of the primary
     * additive measure; ratios average (never sum); statistics never fold.
     *
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $numerics
     * @param  array<string, string>  $measureTypes  field_id → measure type
     * @param  list<array<string, mixed>>  $booleans
     * @return list<array<string, mixed>>
     */
    private function suggestKpis(array $object, string $grain, array $numerics, array $measureTypes, array $booleans, bool $es): array
    {
        $kpis = [];

        if ($this->semantics->countIsMeaningful($grain)) {
            $kpis[] = [
                'label' => ($es ? 'Total ' : 'Total ').Str::lower((string) ($object['name'] ?? 'registros')),
                'aggregation' => 'count',
                'icon' => 'inbox',
            ];
        } else {
            // Pre-aggregated grain: the headline total is the SUM of the
            // primary additive column (total_tickets across weeks IS the
            // ticket total — count(rows) would count WEEKS).
            $primary = collect($numerics)->first(
                fn (array $f): bool => ($measureTypes[$f['id']] ?? '') === SemanticProfile::MEASURE_ADDITIVE
                    && preg_match('/total|count|tickets|orders|volumen|cantidad/i', (string) $f['slug']) === 1,
            ) ?? collect($numerics)->first(
                fn (array $f): bool => ($measureTypes[$f['id']] ?? '') === SemanticProfile::MEASURE_ADDITIVE,
            );
            if ($primary !== null) {
                $kpis[] = [
                    'label' => ($es ? 'Total ' : 'Total ').Str::lower((string) ($primary['name'] ?? $primary['slug'])),
                    'aggregation' => 'sum',
                    'field_id' => $primary['id'],
                    'icon' => 'inbox',
                ];
            }
        }

        foreach ($numerics as $field) {
            if (count($kpis) >= self::MAX_KPIS) {
                break;
            }
            $type = $measureTypes[$field['id']] ?? SemanticProfile::MEASURE_ADDITIVE;
            $legal = $this->semantics->legalKpiAggregations($type, $grain);
            if ($legal === []) {
                continue; // statistics live in charts, per dimension
            }
            if (collect($kpis)->contains(fn (array $k): bool => ($k['field_id'] ?? null) === $field['id'])) {
                continue;
            }

            $slug = (string) ($field['slug'] ?? '');
            $name = (string) ($field['name'] ?? $slug);

            // Duration-like RAW measurements → median + P95 (the SLO pair).
            if ($grain === SemanticProfile::GRAIN_RAW
                && preg_match('/minut|hora|hour|time|dur|dias|days/i', $slug) === 1
                && in_array('median', $legal, true)) {
                $kpis[] = ['label' => ($es ? 'Mediana ' : 'Median ').$name, 'aggregation' => 'median', 'field_id' => $field['id'], 'icon' => 'clock', 'delta_good' => 'down'];
                if (count($kpis) < self::MAX_KPIS) {
                    $kpis[] = ['label' => 'P95 '.$name, 'aggregation' => 'p95', 'field_id' => $field['id'], 'icon' => 'gauge', 'delta_good' => 'down'];
                }

                continue;
            }

            if ($type === SemanticProfile::MEASURE_RATIO) {
                $kpis[] = [
                    'label' => ($es ? 'Promedio ' : 'Average ').$name,
                    'aggregation' => 'avg',
                    'field_id' => $field['id'],
                    'icon' => 'star',
                ];

                continue;
            }

            if (in_array('sum', $legal, true)) {
                $kpis[] = [
                    'label' => ($es ? 'Suma ' : 'Sum of ').$name,
                    'aggregation' => 'sum',
                    'field_id' => $field['id'],
                    'icon' => 'sigma',
                ];
            }
        }

        foreach ($booleans as $field) {
            if (count($kpis) >= self::MAX_KPIS || ! $this->semantics->countIsMeaningful($grain)) {
                break;
            }
            $kpis[] = [
                'label' => (string) ($field['name'] ?? $field['slug']),
                'aggregation' => 'count',
                'filter' => ['op' => 'eq', 'field_id' => $field['id'], 'value' => true],
                'icon' => 'alert-triangle',
                'delta_good' => 'down',
            ];
        }

        // A pre-aggregated object with ONLY statistics (a percentile table)
        // still deserves a headline: the extreme of the leading statistic.
        if ($kpis === [] && $numerics !== []) {
            $lead = $numerics[0];
            $kpis[] = [
                'label' => ($es ? 'Máx ' : 'Max ').(string) ($lead['name'] ?? $lead['slug']),
                'aggregation' => 'max',
                'field_id' => $lead['id'],
                'icon' => 'gauge',
            ];
        }

        return $kpis;
    }

    /**
     * Charts that fit the data's shape. Story order: magnitude/trend first,
     * concentration next, comparisons last.
     *
     * @param  array<string, mixed>|null  $dateField
     * @param  list<array<string, mixed>>  $categoricals
     * @param  list<array<string, mixed>>  $numerics
     * @param  array<string, string>  $measureTypes
     * @param  array<string, array{values: list<mixed>, distinct: int, null_rate: float, all_equal: bool}>  $stats
     * @return list<array<string, mixed>>
     */
    private function suggestCharts(string $grain, ?array $dateField, array $categoricals, array $numerics, array $measureTypes, array $stats, bool $es): array
    {
        $charts = [];
        $additives = array_values(array_filter(
            $numerics,
            fn (array $f): bool => ($measureTypes[$f['id']] ?? '') === SemanticProfile::MEASURE_ADDITIVE,
        ));
        $statistics = array_values(array_filter(
            $numerics,
            fn (array $f): bool => ($measureTypes[$f['id']] ?? '') === SemanticProfile::MEASURE_STATISTIC,
        ));
        $ratios = array_values(array_filter(
            $numerics,
            fn (array $f): bool => ($measureTypes[$f['id']] ?? '') === SemanticProfile::MEASURE_RATIO,
        ));

        // 1) Trend. Skip when the sampled data can't draw one (< 3 buckets).
        //    On pre-aggregated rows the y is the SUM of the primary additive —
        //    count(x) would count buckets: the chart-shaped "Total: 5" lie.
        $spanDays = $dateField !== null && $stats !== []
            ? $this->semantics->temporalSpanDays($stats[$dateField['id']]['values'] ?? [])
            : null;
        $bucketCount = $dateField !== null && $stats !== []
            ? ($stats[$dateField['id']]['distinct'] ?? 0)
            : null;

        if ($dateField !== null && ($bucketCount === null || $bucketCount >= 3)) {
            $bucket = match (true) {
                $spanDays !== null && $spanDays <= 14 => 'day',
                $spanDays !== null && $spanDays > 120 => 'month',
                default => 'week',
            };
            $trend = [
                'label' => $es ? 'Tendencia en el tiempo' : 'Trend over time',
                'chart_type' => 'line',
                'x_field_id' => $dateField['id'],
                'bucket' => $bucket,
            ];
            if ($grain === SemanticProfile::GRAIN_RAW) {
                $trend['aggregation'] = 'count';
                $trend['label'] = $es ? 'Volumen en el tiempo' : 'Volume over time';
            } elseif ($additives !== []) {
                $trend['aggregation'] = 'sum';
                $trend['y_field_id'] = $additives[0]['id'];
                $trend['label'] = ($es ? 'Evolución de ' : 'Evolution of ').Str::lower((string) ($additives[0]['name'] ?? $additives[0]['slug']));
            } elseif ($ratios !== []) {
                $trend['aggregation'] = 'avg';
                $trend['y_field_id'] = $ratios[0]['id'];
                $trend['label'] = ($es ? 'Evolución de ' : 'Evolution of ').Str::lower((string) ($ratios[0]['name'] ?? $ratios[0]['slug']));
            } else {
                $trend = null;
            }
            if ($trend !== null) {
                $charts[] = $trend;
            }
        }

        // 2) Concentration: breakdowns per categorical, form chosen by REAL
        //    cardinality (donut needs few slices; many go horizontal, capped).
        $breakdownTypes = ['donut', 'bar', 'treemap'];
        foreach ($categoricals as $i => $field) {
            if (count($charts) >= self::MAX_CHARTS - 1) {
                break;
            }
            $distinct = $stats !== [] ? ($stats[$field['id']]['distinct'] ?? null) : null;
            if ($distinct !== null && $distinct < 2) {
                continue; // one slice is not a breakdown
            }

            $chart = [
                'label' => ($es ? 'Por ' : 'By ').Str::lower((string) ($field['name'] ?? $field['slug'])),
                'group_by_field_id' => $field['id'],
                'limit' => self::BREAKDOWN_LIMIT,
                'chart_type' => ($distinct !== null && $distinct > 8)
                    ? 'hbar'
                    : $breakdownTypes[$i % count($breakdownTypes)],
            ];
            if ($grain === SemanticProfile::GRAIN_RAW) {
                $chart['aggregation'] = 'count';
            } elseif ($additives !== []) {
                $chart['aggregation'] = 'sum';
                $chart['y_field_id'] = $additives[0]['id'];
            } else {
                continue; // nothing legal to size the slices with
            }
            $charts[] = $chart;
        }

        // 3) Statistics per dimension: shown, never folded. avg over one row
        //    per group is the identity, so the number rendered IS the value.
        if ($statistics !== [] && $categoricals !== []) {
            foreach (array_slice($statistics, 0, 2) as $stat) {
                if (count($charts) >= self::MAX_CHARTS) {
                    break;
                }
                $charts[] = [
                    'label' => (string) ($stat['name'] ?? $stat['slug']).($es ? ' por ' : ' by ').Str::lower((string) ($categoricals[0]['name'] ?? $categoricals[0]['slug'])),
                    'chart_type' => 'hbar',
                    'aggregation' => 'avg',
                    'y_field_id' => $stat['id'],
                    'group_by_field_id' => $categoricals[0]['id'],
                    'limit' => self::BREAKDOWN_LIMIT,
                ];
            }
        }

        // 4) Distribution on RAW rows only — a box plot needs raw points.
        if ($grain === SemanticProfile::GRAIN_RAW && $numerics !== [] && $categoricals !== [] && count($charts) < self::MAX_CHARTS) {
            $num = $numerics[0];
            $charts[] = [
                'label' => (string) ($num['name'] ?? $num['slug']).($es ? ' por ' : ' by ').Str::lower((string) ($categoricals[0]['name'] ?? $categoricals[0]['slug'])),
                'chart_type' => 'box',
                'aggregation' => 'avg',
                'y_field_id' => $num['id'],
                'group_by_field_id' => $categoricals[0]['id'],
            ];
        }

        // 5) Stacked composition over time needs record-level rows.
        if ($grain === SemanticProfile::GRAIN_RAW && $dateField !== null && $categoricals !== [] && count($charts) < self::MAX_CHARTS) {
            $cat = $categoricals[0];
            $distinct = $stats !== [] ? ($stats[$cat['id']]['distinct'] ?? 5) : 5;
            if ($distinct >= 2 && $distinct <= 8) {
                $charts[] = [
                    'label' => ($es ? 'Tendencia semanal por ' : 'Weekly trend by ').Str::lower((string) ($cat['name'] ?? $cat['slug'])),
                    'chart_type' => 'area',
                    'aggregation' => 'count',
                    'x_field_id' => $dateField['id'],
                    'bucket' => 'week',
                    'series_field_id' => $cat['id'],
                    'stacked' => true,
                ];
            }
        }

        // Last resort: a numbers-only object still gets a legal bar per
        // numeric — a chartless spec is never suggested.
        if ($charts === []) {
            foreach (array_slice($numerics, 0, 3) as $i => $num) {
                $charts[] = [
                    'label' => (string) ($num['name'] ?? $num['slug']),
                    'chart_type' => $i === 0 ? 'bar' : ($i === 1 ? 'hbar' : 'radar'),
                    'aggregation' => ($measureTypes[$num['id']] ?? '') === SemanticProfile::MEASURE_ADDITIVE ? 'sum' : 'avg',
                    'y_field_id' => $num['id'],
                ];
            }
        }

        return $charts;
    }

    /**
     * Insight SCAFFOLDS: correct variants + live computes; the bodies are
     * deliberately plain statements the model should override with real
     * conclusions.
     *
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $categoricals
     * @param  list<array<string, mixed>>  $booleans
     * @return list<array<string, mixed>>
     */
    private function suggestInsights(array $object, array $categoricals, array $booleans, bool $es): array
    {
        $objectId = $object['id'] ?? null;
        $insights = [[
            'variant' => 'conclusion',
            'title' => $es ? 'Volumen del periodo' : 'Period volume',
            'body' => $es
                ? 'Registros dentro de la ventana seleccionada — compara contra el periodo anterior para leer la tendencia.'
                : 'Records inside the selected window — compare with the previous period to read the trend.',
            'compute' => ['query' => ['object_id' => $objectId], 'aggregation' => 'count'],
        ]];

        if ($booleans !== []) {
            $flag = $booleans[0];
            $insights[] = [
                'variant' => 'risk',
                'title' => (string) ($flag['name'] ?? $flag['slug']),
                'body' => $es
                    ? 'Casos con esta marca activa en la ventana — priorízalos para evitar escalamientos.'
                    : 'Cases with this flag set inside the window — prioritise them to avoid escalations.',
                'compute' => [
                    'query' => ['object_id' => $objectId, 'filter' => ['op' => 'eq', 'field_id' => $flag['id'], 'value' => true]],
                    'aggregation' => 'count',
                ],
            ];
        }

        if ($categoricals !== []) {
            $cat = $categoricals[0];
            $insights[] = [
                'variant' => 'recommendation',
                'title' => ($es ? 'Concentración por ' : 'Concentration by ').Str::lower((string) ($cat['name'] ?? $cat['slug'])),
                'body' => $es
                    ? 'El valor dominante concentra la mayor parte del volumen — candidato #1 a deflectar o automatizar.'
                    : 'The dominant value concentrates most of the volume — the #1 candidate to deflect or automate.',
            ];
        }

        return $insights;
    }
}
