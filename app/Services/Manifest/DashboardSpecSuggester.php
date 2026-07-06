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

    /** With several objects, how much of the board the primary keeps. */
    private const PRIMARY_KPIS = 4;

    private const PRIMARY_CHARTS = 4;

    private const MAX_SECONDARIES = 3;

    /**
     * Multi-object composition: the PRIMARY (first) object drives the board's
     * skeleton, and each additional object with rows contributes its strongest
     * pieces — its trend chart (with several time-axed objects that series is
     * usually THE thing the user asked for) and its leading breakdown, plus
     * its headline KPI — each tagged with object_slug so the compiler reads
     * every block from its own object. Observed gap this closes: a 4-object
     * NPS build used only the comments object, and the requested weekly
     * nps_score evolution never rendered despite being acquired.
     *
     * @param  list<array<string, mixed>>  $objects  ordered primary-first
     * @param  array<string, list<array<string, mixed>>>  $rowsByObject  keyed by object id
     * @return array<string, mixed> spec in add_dashboard_page's input shape
     */
    public function suggestMulti(array $objects, string $lang = 'es', array $rowsByObject = []): array
    {
        $objects = array_values(array_filter($objects, 'is_array'));
        if ($objects === []) {
            return [];
        }

        $primary = $objects[0];
        $spec = $this->suggest($primary, $lang, $rowsByObject[$primary['id'] ?? ''] ?? []);
        $secondaries = array_values(array_filter(
            array_slice($objects, 1),
            fn (array $o): bool => ($rowsByObject[$o['id'] ?? ''] ?? []) !== [],
        ));
        if ($secondaries === []) {
            return $spec;
        }

        // Make room for the guests: the primary keeps its strongest pieces
        // (the suggester already orders by story importance).
        $spec['kpis'] = array_slice($spec['kpis'] ?? [], 0, self::PRIMARY_KPIS);
        $spec['charts'] = array_slice($spec['charts'] ?? [], 0, self::PRIMARY_CHARTS);

        foreach (array_slice($secondaries, 0, self::MAX_SECONDARIES) as $secondary) {
            $slug = $secondary['slug'] ?? null;
            if ($slug === null) {
                continue;
            }
            $mini = $this->suggest($secondary, $lang, $rowsByObject[$secondary['id'] ?? ''] ?? []);
            $name = (string) ($secondary['name'] ?? $slug);

            $charts = collect($mini['charts'] ?? []);
            $trend = $charts->first(fn (array $c): bool => isset($c['x_field_id']));
            $breakdown = $charts->first(fn (array $c): bool => isset($c['group_by_field_id']) && ! isset($c['x_field_id']));
            foreach ([$trend, $breakdown] as $chart) {
                if ($chart === null || count($spec['charts']) >= self::MAX_CHARTS) {
                    continue;
                }
                $chart['object_slug'] = $slug;
                $chart['label'] = $this->labelWithObject((string) ($chart['label'] ?? ''), $name);
                $spec['charts'][] = $this->varyForm($chart, $spec['charts']);
            }

            $kpi = ($mini['kpis'] ?? [])[0] ?? null;
            if ($kpi !== null && count($spec['kpis']) < self::MAX_KPIS) {
                $kpi['object_slug'] = $slug;
                $kpi['label'] = $this->labelWithObject((string) ($kpi['label'] ?? ''), $name);
                $spec['kpis'][] = $kpi;
            }

            // One temporal contributor is enough to make the range filter
            // worth having, even when the primary itself is dateless (the
            // compiler wires only the objects that can honestly listen).
            if (($mini['date_field_id'] ?? null) !== null) {
                $spec['include_date_filter'] = true;
            }
        }

        return $spec;
    }

    /**
     * Numeric fields named like the object itself (nps_time_series →
     * nps_score) — the measure the user almost certainly means when they
     * name the object's topic.
     *
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $numerics
     * @return list<array<string, mixed>>
     */
    private function topicalMeasures(array $object, array $numerics): array
    {
        $tokens = collect(preg_split('/[^a-z0-9]+/i', Str::lower(Str::ascii(($object['slug'] ?? '').' '.($object['name'] ?? '')))) ?: [])
            ->filter(fn ($t) => mb_strlen((string) $t) >= 3 && ! in_array($t, ['time', 'series', 'weekly', 'daily', 'monthly', 'data', 'the', 'por', 'del'], true))
            ->unique()->values();
        if ($tokens->isEmpty()) {
            return [];
        }

        return array_values(array_filter($numerics, function (array $f) use ($tokens): bool {
            $slug = Str::lower((string) ($f['slug'] ?? ''));

            return $tokens->contains(fn (string $t): bool => str_contains($slug, $t));
        }));
    }

    /**
     * Every secondary's mini-spec leads with the same forms (line trend,
     * donut breakdown), so a naive merge repeats a chart_type past the
     * variety lint's cap of 2. Re-form the newcomer when its type is taken.
     *
     * @param  array<string, mixed>  $chart
     * @param  list<array<string, mixed>>  $existing
     * @return array<string, mixed>
     */
    private function varyForm(array $chart, array $existing): array
    {
        $counts = array_count_values(array_map(fn (array $c): string => (string) ($c['chart_type'] ?? ''), $existing));
        $type = (string) ($chart['chart_type'] ?? 'bar');
        if (($counts[$type] ?? 0) < 2) {
            return $chart;
        }

        $alternatives = in_array($type, ['line', 'area'], true)
            ? ['area', 'line', 'bar']
            : ['hbar', 'treemap', 'bar', 'donut'];
        foreach ($alternatives as $alt) {
            if ($alt !== $type && ($counts[$alt] ?? 0) < 2) {
                $chart['chart_type'] = $alt;
                break;
            }
        }

        return $chart;
    }

    /** "Evolución de nps score · NPS Semanal" — say whose number it is. */
    private function labelWithObject(string $label, string $objectName): string
    {
        if ($objectName === '' || mb_stripos($label, $objectName) !== false) {
            return $label;
        }

        return trim($label.' · '.$objectName);
    }

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
        // Numeric identifiers are labels, not measures — out of KPIs, charts
        // and last-resort bars alike.
        $numerics = array_values(array_filter(
            $numerics,
            fn (array $f): bool => ($measureTypes[$f['id']] ?? '') !== SemanticProfile::MEASURE_IDENTIFIER,
        ));

        return array_filter([
            'object_slug' => $object['slug'] ?? null,
            'title' => ($es ? 'Análisis de ' : 'Analysis of ').($object['name'] ?? $object['slug'] ?? ''),
            'date_field_id' => $dateField['id'] ?? null,
            // Without a real temporal field the compiler would fall back to
            // sys_created_at — which connected rows DON'T carry, so the range
            // filter silently deletes every row and the whole board renders
            // empty (observed: an entire benchmark scenario scored 1/5).
            'include_date_filter' => $dateField !== null,
            'kpis' => $this->suggestKpis($object, $grain, $numerics, $measureTypes, $booleans, $es),
            'charts' => $this->suggestCharts($grain, $dateField, $categoricals, $numerics, $measureTypes, $stats, $es, $object),
            'insights' => $this->suggestInsights($object, $categoricals, $booleans, $es),
        ], fn ($v) => $v !== null && $v !== '');
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
            // The object's namesake ratio leads when there is one: an NPS
            // series headlines with the average nps_score, not with the sum
            // of a generic additive.
            $namesake = collect($this->topicalMeasures($object, $numerics))->first(
                fn (array $f): bool => ($measureTypes[$f['id']] ?? '') === SemanticProfile::MEASURE_RATIO,
            );
            if ($namesake !== null) {
                $kpis[] = [
                    'label' => ($es ? 'Promedio ' : 'Average ').(string) ($namesake['name'] ?? $namesake['slug']),
                    'aggregation' => 'avg',
                    'field_id' => $namesake['id'],
                    'icon' => 'star',
                ];
            }

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
    private function suggestCharts(string $grain, ?array $dateField, array $categoricals, array $numerics, array $measureTypes, array $stats, bool $es, array $object = []): array
    {
        $charts = [];

        // On a time series, the bucket's label column is the TIME AXIS in
        // costume — grouping by it re-plots the trend as bars (observed:
        // «Por period label» AND «Avg Csat por period label» duplicating the
        // weekly evolution; the latter came from a section below that used
        // $categoricals directly, unfiltered — filtering once, up front, for
        // every section closes that gap structurally instead of per-section.
        if ($grain === SemanticProfile::GRAIN_TIME_SERIES) {
            $categoricals = array_values(array_filter(
                $categoricals,
                fn (array $f): bool => preg_match('/label|bucket|period|semana|week/i', (string) ($f['slug'] ?? '')) !== 1,
            ));
        }

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
            } else {
                // The measure the user means is usually the one the OBJECT is
                // named after: nps_time_series → nps_score, not its first
                // additive (a prod NPS board charted `responses` and the
                // requested score never rendered). Topical ratio first, then
                // topical additive, then the generic order.
                $topical = $this->topicalMeasures($object, $numerics);
                $pick = collect([
                    [collect($topical)->first(fn (array $f): bool => ($measureTypes[$f['id']] ?? '') === SemanticProfile::MEASURE_RATIO), 'avg'],
                    [collect($topical)->first(fn (array $f): bool => ($measureTypes[$f['id']] ?? '') === SemanticProfile::MEASURE_ADDITIVE), 'sum'],
                    [$additives[0] ?? null, 'sum'],
                    [$ratios[0] ?? null, 'avg'],
                ])->first(fn (array $c): bool => $c[0] !== null);

                if ($pick !== null) {
                    [$field, $agg] = $pick;
                    $trend['aggregation'] = $agg;
                    $trend['y_field_id'] = $field['id'];
                    $trend['label'] = ($es ? 'Evolución de ' : 'Evolution of ').Str::lower((string) ($field['name'] ?? $field['slug']));
                } else {
                    $trend = null;
                }
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
