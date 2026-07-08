<?php

namespace App\Services\Manifest;

use App\Services\Express\ComputedFactsBuilder;
use App\Services\Express\FactNarrator;
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

    public function __construct(
        private readonly SemanticProfile $semantics = new SemanticProfile,
        private readonly ComputedFactsBuilder $factsBuilder = new ComputedFactsBuilder,
        private readonly FactNarrator $narrator = new FactNarrator,
    ) {}

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
     * @param  list<string>  $promptTopics  the request's subject words (e.g. ['nps']) — feature that measure
     * @return array<string, mixed> spec in add_dashboard_page's input shape
     */
    public function suggestMulti(array $objects, string $lang = 'es', array $rowsByObject = [], array $promptTopics = []): array
    {
        $objects = array_values(array_filter($objects, 'is_array'));
        if ($objects === []) {
            return [];
        }

        $primary = $objects[0];
        $spec = $this->suggest($primary, $lang, $rowsByObject[$primary['id'] ?? ''] ?? [], $promptTopics);
        $secondaries = array_values(array_filter(
            array_slice($objects, 1),
            fn (array $o): bool => ($rowsByObject[$o['id'] ?? ''] ?? []) !== [],
        ));
        if ($secondaries === []) {
            return $spec;
        }

        // Make room for the guests: the primary keeps its strongest pieces
        // (the suggester already orders by story importance) — but its EXTRA
        // trends (a multi-measure series emits up to three) step aside first:
        // a guest object's trend+breakdown says more than the primary's third
        // line. What gets displaced returns below if the guests leave room.
        $spec['kpis'] = array_slice($spec['kpis'] ?? [], 0, self::PRIMARY_KPIS);
        $primaryCharts = collect($spec['charts'] ?? []);
        $primaryTrends = $primaryCharts->filter(fn (array $c): bool => isset($c['x_field_id']))->values();
        $ordered = collect([$primaryTrends->first()])->filter()
            ->concat($primaryCharts->reject(fn (array $c): bool => isset($c['x_field_id'])))
            ->concat($primaryTrends->slice(1))
            ->values()->all();
        $spec['charts'] = array_slice($ordered, 0, self::PRIMARY_CHARTS);
        $displaced = array_slice($ordered, self::PRIMARY_CHARTS);

        // Cross-object identity of everything already on the board, so three
        // sources of "total tickets" land as ONE number plus each source's
        // next-best DISTINCT metric — not the same figure three times.
        $slugById = $this->fieldSlugIndex($primary);
        $kpiIdentities = array_map(fn (array $k): ?array => $this->kpiIdentity($k, $slugById), $spec['kpis']);
        $trendIdentities = collect($spec['charts'])
            ->filter(fn (array $c): bool => isset($c['x_field_id']))
            ->map(fn (array $c): ?array => $this->chartMeasureIdentity($c, $slugById))
            ->all();

        foreach (array_slice($secondaries, 0, self::MAX_SECONDARIES) as $secondary) {
            $slug = $secondary['slug'] ?? null;
            if ($slug === null) {
                continue;
            }
            $mini = $this->suggest($secondary, $lang, $rowsByObject[$secondary['id'] ?? ''] ?? [], $promptTopics);
            $name = (string) ($secondary['name'] ?? $slug);
            $miniSlugs = $this->fieldSlugIndex($secondary);

            $charts = collect($mini['charts'] ?? []);
            $trend = $charts->first(fn (array $c): bool => isset($c['x_field_id']));
            $breakdown = $charts->first(fn (array $c): bool => isset($c['group_by_field_id']) && ! isset($c['x_field_id']));

            // A second trend of the SAME measure re-plots an existing line
            // under another source's name — skip it; the breakdown still lands.
            if ($trend !== null) {
                $trendIdentity = $this->chartMeasureIdentity($trend, $miniSlugs);
                if ($this->isDuplicateIdentity($trendIdentity, $trendIdentities)) {
                    $trend = null;
                } else {
                    $trendIdentities[] = $trendIdentity;
                }
            }

            foreach ([$trend, $breakdown] as $chart) {
                if ($chart === null || count($spec['charts']) >= self::MAX_CHARTS) {
                    continue;
                }
                $chart['object_slug'] = $slug;
                $chart['label'] = $this->labelWithObject((string) ($chart['label'] ?? ''), $name);
                $spec['charts'][] = $this->varyForm($chart, $spec['charts']);
            }

            // The secondary's FIRST KPI whose measure isn't already on the
            // band; when its headline duplicates (every ticket source leads
            // with "total tickets"), its next metric is genuinely new info.
            foreach ($mini['kpis'] ?? [] as $kpi) {
                if (count($spec['kpis']) >= self::MAX_KPIS) {
                    break;
                }
                $identity = $this->kpiIdentity($kpi, $miniSlugs);
                if ($this->isDuplicateIdentity($identity, $kpiIdentities)) {
                    continue;
                }
                $kpi['object_slug'] = $slug;
                $kpi['label'] = $this->labelWithObject((string) ($kpi['label'] ?? ''), $name);
                $spec['kpis'][] = $kpi;
                $kpiIdentities[] = $identity;
                break;
            }

            // One temporal contributor is enough to make the range filter
            // worth having, even when the primary itself is dateless (the
            // compiler wires only the objects that can honestly listen).
            if (($mini['date_field_id'] ?? null) !== null) {
                $spec['include_date_filter'] = true;
            }
        }

        // The primary pieces the guests displaced come back while there's room.
        foreach ($displaced as $chart) {
            if (count($spec['charts']) >= self::MAX_CHARTS) {
                break;
            }
            $spec['charts'][] = $this->varyForm($chart, $spec['charts']);
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
    private function topicalMeasures(array $object, array $numerics, array $promptTopics = []): array
    {
        $objectTokens = collect(preg_split('/[^a-z0-9]+/i', Str::lower(Str::ascii(($object['slug'] ?? '').' '.($object['name'] ?? '')))) ?: [])
            ->filter(fn ($t) => mb_strlen((string) $t) >= 3 && ! in_array($t, ['time', 'series', 'weekly', 'daily', 'monthly', 'data', 'the', 'por', 'del'], true))
            ->unique()->values();
        $promptTokens = collect($promptTopics)
            ->filter(fn ($t) => mb_strlen((string) $t) >= 3)->unique()->values();

        $matches = fn (array $f, $tokens): bool => $tokens->contains(
            fn (string $t): bool => $t !== '' && str_contains(Str::lower((string) ($f['slug'] ?? '')), $t),
        );

        // A measure the user NAMED in the prompt (nps → nps_score) leads over the
        // object's own topic — that's the number they came for, wherever it
        // lives. Then the object-topic measures, then nothing.
        $byPrompt = array_values(array_filter($numerics, fn (array $f): bool => $matches($f, $promptTokens)));
        $byObject = array_values(array_filter(
            $numerics,
            fn (array $f): bool => $matches($f, $objectTokens) && ! $matches($f, $promptTokens),
        ));

        return array_merge($byPrompt, $byObject);
    }

    /**
     * The first numeric field whose slug matches a word the user put in the
     * prompt — the measure they explicitly asked for (nps → nps_score). Null
     * when the prompt names no measure that exists here. Identifiers are already
     * out of $numerics, so any match is a real measure.
     *
     * @param  list<array<string, mixed>>  $numerics
     * @param  list<string>  $promptTopics
     * @return array<string, mixed>|null
     */
    private function requestedMeasure(array $numerics, array $promptTopics): ?array
    {
        $tokens = collect($promptTopics)->filter(fn ($t) => mb_strlen((string) $t) >= 3);
        if ($tokens->isEmpty()) {
            return null;
        }

        foreach ($numerics as $field) {
            $slug = Str::lower((string) ($field['slug'] ?? ''));
            if ($tokens->contains(fn (string $t): bool => $t !== '' && str_contains($slug, $t))) {
                return $field;
            }
        }

        return null;
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

    /**
     * A connected list operation that returns only its most-recent N rows
     * (mode:latest/recent) is a recency-capped SAMPLE, not the full series —
     * counting it over time plots the sampling window, not a trend. Mirrors the
     * penalty SuggestSpecPhase applies when ranking objects, and the compiler's
     * count-over-time guard.
     *
     * @param  array<string, mixed>  $object
     */
    private function isCappedSample(array $object): bool
    {
        $mode = strtolower((string) ($object['source']['operations']['list']['arguments']['mode'] ?? ''));

        return in_array($mode, ['latest', 'recent'], true);
    }

    /** "Evolución de nps score · NPS Semanal" — say whose number it is. */
    private function labelWithObject(string $label, string $objectName): string
    {
        if ($objectName === '' || mb_stripos($label, $objectName) !== false) {
            return $label;
        }

        return trim($label.' · '.$objectName);
    }

    /** @return array<string, string> field_id → slug for one object */
    private function fieldSlugIndex(array $object): array
    {
        $index = [];
        foreach ($object['fields'] ?? [] as $field) {
            if (is_array($field) && isset($field['id'])) {
                $index[$field['id']] = (string) ($field['slug'] ?? '');
            }
        }

        return $index;
    }

    /**
     * The cross-object identity of a measure: its slug's MEANINGFUL tokens
     * (generic total/count words dropped) plus an aggregation class where
     * count and sum collapse to "volume" — count(ticket rows) and
     * sum(total_tickets) are the same headline arriving from two sources.
     * Null when nothing meaningful remains (can't tell → never a duplicate).
     *
     * @return array{tokens: list<string>, agg: string}|null
     */
    private function measureIdentity(string $slugOrLabel, string $aggregation): ?array
    {
        $tokens = collect(preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($slugOrLabel))) ?: [])
            ->filter(fn ($t) => mb_strlen((string) $t) >= 3
                && ! in_array($t, ['total', 'totals', 'suma', 'num', 'numero', 'cantidad', 'count', 'del', 'the', 'por', 'per'], true))
            ->unique()->sort()->values()->all();
        if ($tokens === []) {
            return null;
        }

        return [
            'tokens' => $tokens,
            'agg' => in_array($aggregation, ['count', 'sum'], true) ? 'volume' : $aggregation,
        ];
    }

    /**
     * @param  array<string, mixed>  $kpi
     * @param  array<string, string>  $slugById
     * @return array{tokens: list<string>, agg: string}|null
     */
    private function kpiIdentity(array $kpi, array $slugById): ?array
    {
        $slug = $slugById[$kpi['field_id'] ?? ''] ?? '';

        return $this->measureIdentity(
            $slug !== '' ? $slug : (string) ($kpi['label'] ?? ''),
            (string) ($kpi['aggregation'] ?? 'count'),
        );
    }

    /**
     * @param  array<string, mixed>  $chart
     * @param  array<string, string>  $slugById
     * @return array{tokens: list<string>, agg: string}|null
     */
    private function chartMeasureIdentity(array $chart, array $slugById): ?array
    {
        $slug = $slugById[$chart['y_field_id'] ?? ''] ?? '';
        if ($slug === '') {
            return null; // count trends carry no measure to collide on
        }

        return $this->measureIdentity($slug, (string) ($chart['aggregation'] ?? 'count'));
    }

    /**
     * Duplicate when the aggregation class matches and either token set
     * contains the other: totals_total_tickets ⇒ {tickets} is subsumed by
     * tickets_creados ⇒ {creados, tickets} — same measure, extra qualifier.
     *
     * @param  array{tokens: list<string>, agg: string}|null  $candidate
     * @param  list<array{tokens: list<string>, agg: string}|null>  $existing
     */
    private function isDuplicateIdentity(?array $candidate, array $existing): bool
    {
        if ($candidate === null) {
            return false;
        }

        foreach ($existing as $identity) {
            if ($identity === null || $identity['agg'] !== $candidate['agg']) {
                continue;
            }
            $a = $candidate['tokens'];
            $b = $identity['tokens'];
            if (array_diff($a, $b) === [] || array_diff($b, $a) === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $object  manifest object_definition
     * @param  list<array<string, mixed>>  $rows  sampled external rows (optional but recommended)
     * @param  list<string>  $promptTopics  request subject words — a measure field matching one leads
     * @return array<string, mixed> spec in add_dashboard_page's input shape
     */
    public function suggest(array $object, string $lang = 'es', array $rows = [], array $promptTopics = []): array
    {
        $es = $lang !== 'en';
        $fields = array_values(array_filter($object['fields'] ?? [], 'is_array'));

        $grain = $this->semantics->grainOf($object, $rows);
        $stats = $rows !== [] ? $this->semantics->columnStats($object, $rows) : [];

        $usable = fn (array $f): bool => $stats === []
            || (($stats[$f['id']]['null_rate'] ?? 0) <= 0.6 && ! ($stats[$f['id']]['all_equal'] ?? false));

        $dateField = $this->pickDateField($fields);
        // On a time series, the bucket's LABEL column is the time axis in
        // costume — not a real category. Filter it out ONCE here so charts,
        // stat-per-dimension and INSIGHT scaffolds all share the clean set
        // (a stray «Concentración por bucket label» insight shipped when the
        // insight scaffold got the unfiltered list).
        $categoricals = $this->realCategoricals(
            array_values(array_filter($this->categoricalFields($fields), $usable)),
            $grain,
        );
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

        // Open the board on a window the data actually LIVES in: a monthly or
        // yearly series filtered to the fixed 30-day default renders empty.
        // The sampled span picks the preset (validated by the compiler).
        $defaultRange = $this->defaultRange($dateField, $stats);

        return array_filter([
            'object_slug' => $object['slug'] ?? null,
            'title' => ($es ? 'Análisis de ' : 'Analysis of ').($object['name'] ?? $object['slug'] ?? ''),
            'date_field_id' => $dateField['id'] ?? null,
            // Without a real temporal field the compiler would fall back to
            // sys_created_at — which connected rows DON'T carry, so the range
            // filter silently deletes every row and the whole board renders
            // empty (observed: an entire benchmark scenario scored 1/5).
            'include_date_filter' => $dateField !== null,
            'default_range' => $defaultRange,
            'kpis' => $this->suggestKpis($object, $grain, $numerics, $measureTypes, $booleans, $es, $promptTopics, $stats, $dateField, $defaultRange),
            'charts' => $this->suggestCharts($grain, $dateField, $categoricals, $numerics, $measureTypes, $stats, $es, $object, $promptTopics),
            'insights' => $this->suggestInsights($object, $categoricals, $booleans, $es, $rows),
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * The range preset the board OPENS on, from the sampled data's temporal
     * span: ≤35 days → 30d; ≤120 → 90d; longer → 1y. Unknown span (no date,
     * no rows) keeps the product default. Must be one of the filter bar's
     * real presets — the compiler validates.
     *
     * @param  array<string, mixed>|null  $dateField
     * @param  array<string, array{values: list<mixed>, distinct: int, null_rate: float, all_equal: bool}>  $stats
     */
    private function defaultRange(?array $dateField, array $stats): ?string
    {
        if ($dateField === null || $stats === []) {
            return null;
        }
        $span = $this->semantics->temporalSpanDays($stats[$dateField['id']]['values'] ?? []);
        if ($span <= 0) {
            return null;
        }

        return match (true) {
            $span <= 35 => '30d',
            $span <= 120 => '90d',
            default => '1y',
        };
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
     * Real breakdown dimensions: on a time series, drop the bucket-LABEL column
     * (period_label, bucket_label, semana…) — it is the time axis wearing a
     * string costume, not a category. Grouping/concentrating by it re-plots the
     * trend or narrates nonsense ("Concentración por bucket label"). Every
     * section (charts, stat-per-dimension, insight scaffolds) reads from here.
     *
     * @param  list<array<string, mixed>>  $categoricals
     * @return list<array<string, mixed>>
     */
    private function realCategoricals(array $categoricals, string $grain): array
    {
        if ($grain !== SemanticProfile::GRAIN_TIME_SERIES) {
            return $categoricals;
        }

        return array_values(array_filter(
            $categoricals,
            fn (array $f): bool => preg_match('/label|bucket|period|semana|week/i', (string) ($f['slug'] ?? '')) !== 1,
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
    /**
     * "Total X", but never doubled: a field already named "Total Tickets" becomes
     * "Total tickets", not "Total total tickets".
     */
    private function totalLabel(string $name): string
    {
        $clean = trim($name);

        return preg_match('/^(total|suma)\b/i', $clean) === 1
            ? Str::ucfirst(Str::lower($clean))
            : 'Total '.Str::lower($clean);
    }

    private function suggestKpis(array $object, string $grain, array $numerics, array $measureTypes, array $booleans, bool $es, array $promptTopics = [], array $stats = [], ?array $dateField = null, ?string $defaultRange = null): array
    {
        $kpis = [];

        // The measure the user NAMED headlines the band, whatever the object
        // grain — a "dashboard de nps" over a ticket LIST must lead with
        // avg(nps_score), not the ticket count. This is the number they asked
        // for; it lives in a field, not a tool, so nothing else would surface it.
        $requested = $this->requestedMeasure($numerics, $promptTopics);
        if ($requested !== null) {
            $kpis[] = [
                'label' => (string) ($requested['name'] ?? $requested['slug']),
                'aggregation' => ($measureTypes[$requested['id']] ?? '') === SemanticProfile::MEASURE_ADDITIVE ? 'sum' : 'avg',
                'field_id' => $requested['id'],
                'icon' => 'star',
                ...$this->kpiDisplay($requested, $stats),
            ];
        }

        if ($this->semantics->countIsMeaningful($grain)) {
            $kpis[] = [
                'label' => $this->totalLabel((string) ($object['name'] ?? 'registros')),
                'aggregation' => 'count',
                'icon' => 'inbox',
            ];
        } else {
            // The object's namesake ratio leads when there is one: an NPS
            // series headlines with the average nps_score, not with the sum
            // of a generic additive.
            $namesake = collect($this->topicalMeasures($object, $numerics, $promptTopics))->first(
                fn (array $f): bool => ($measureTypes[$f['id']] ?? '') === SemanticProfile::MEASURE_RATIO
                    && ($f['id'] ?? null) !== ($requested['id'] ?? null),
            );
            if ($namesake !== null) {
                $kpis[] = [
                    // Clean field name; the aggregation basis ("promedio del
                    // periodo") is carried by the KPI subtitle the compiler
                    // fills, so a "Promedio …" prefix would just be redundant.
                    'label' => (string) ($namesake['name'] ?? $namesake['slug']),
                    'aggregation' => 'avg',
                    'field_id' => $namesake['id'],
                    'icon' => 'star',
                    ...$this->kpiDisplay($namesake, $stats),
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
            if ($primary !== null && ($primary['id'] ?? null) !== ($requested['id'] ?? null)) {
                $kpis[] = [
                    'label' => $this->totalLabel((string) ($primary['name'] ?? $primary['slug'])),
                    'aggregation' => 'sum',
                    'field_id' => $primary['id'],
                    'icon' => 'inbox',
                ];
            }
        }

        // Rank the remaining numerics by story value instead of API field
        // order: ratios (the qualitative read) first, then additives with the
        // most variation (columnStats distinct), statistics last.
        $ranked = collect($numerics)->values()->sortBy(fn (array $f, int $i): array => [
            match ($measureTypes[$f['id']] ?? '') {
                SemanticProfile::MEASURE_RATIO => 0,
                SemanticProfile::MEASURE_ADDITIVE => 1,
                default => 2,
            },
            -($stats[$f['id']]['distinct'] ?? 0),
            $i,
        ])->values()->all();

        foreach ($ranked as $field) {
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
                $display = $this->kpiDisplay($field, $stats);
                $kpis[] = ['label' => ($es ? 'Mediana ' : 'Median ').$name, 'aggregation' => 'median', 'field_id' => $field['id'], 'icon' => 'clock', 'delta_good' => 'down', ...$display];
                if (count($kpis) < self::MAX_KPIS) {
                    $kpis[] = ['label' => 'P95 '.$name, 'aggregation' => 'p95', 'field_id' => $field['id'], 'icon' => 'gauge', 'delta_good' => 'down', ...$display];
                }

                continue;
            }

            if ($type === SemanticProfile::MEASURE_RATIO) {
                $kpis[] = [
                    // Field name only — the subtitle names it as an average.
                    'label' => $name,
                    'aggregation' => 'avg',
                    'field_id' => $field['id'],
                    'icon' => 'star',
                    ...$this->kpiDisplay($field, $stats),
                ];

                continue;
            }

            if (in_array('sum', $legal, true)) {
                $kpis[] = [
                    // Field name only — the subtitle names it as an accumulated total.
                    'label' => $name,
                    'aggregation' => 'sum',
                    'field_id' => $field['id'],
                    'icon' => 'sigma',
                    ...$this->kpiDisplay($field, $stats),
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

        // One KPI per MEASURE, not per column: sum(total_tickets) next to
        // count(ticket rows) is the same headline twice. Keep the first card
        // of each identity, whatever branch produced it.
        $slugById = $this->fieldSlugIndex($object);
        $seen = [];
        $kpis = array_values(array_filter($kpis, function (array $kpi) use (&$seen, $slugById): bool {
            $identity = $this->kpiIdentity($kpi, $slugById);
            if ($this->isDuplicateIdentity($identity, $seen)) {
                return false;
            }
            $seen[] = $identity;

            return true;
        }));

        // Period-over-period: with a real date axis every card gets a compare
        // window (the PREVIOUS period of whatever preset is selected) so the
        // delta chip renders, plus the field's semantic direction (backlog
        // down = good, containment up = good). The compiler wires the current
        // window; range_prev_start()/range_start() bracket the previous one.
        if ($dateField !== null) {
            $fieldsById = collect($numerics)->keyBy('id');
            $kpis = array_map(function (array $kpi) use ($dateField, $fieldsById, $defaultRange): array {
                $kpi['compare'] ??= $this->previousWindowCompare((string) $dateField['id'], $kpi['filter'] ?? null, $defaultRange ?? '30d');
                if (! array_key_exists('delta_good', $kpi)) {
                    $field = $fieldsById->get($kpi['field_id'] ?? '');
                    $direction = $field !== null ? $this->semantics->deltaGoodOf($field) : null;
                    if ($direction !== null) {
                        $kpi['delta_good'] = $direction;
                    }
                }

                return $kpi;
            }, $kpis);
        }

        return $kpis;
    }

    /**
     * The compare query for a KPI's delta chip: the same measure over the
     * PREVIOUS window of the currently selected preset —
     * [range_prev_start, range_start). The KPI's own filter (a boolean flag,
     * say) applies to both windows so the comparison is apples to apples. On
     * the "Todo" preset both bounds resolve empty and skip, so the chip reads
     * flat instead of lying.
     *
     * @param  array<string, mixed>|null  $ownFilter
     * @return array{filter: array<string, mixed>}
     */
    private function previousWindowCompare(string $dateFieldId, ?array $ownFilter, string $defaultRange = '30d'): array
    {
        $window = ['op' => 'and', 'conditions' => [
            ['op' => 'gte', 'field_id' => $dateFieldId, 'value_expression' => "{{range_prev_start(default(params.range, '{$defaultRange}'))}}"],
            ['op' => 'lt', 'field_id' => $dateFieldId, 'value_expression' => "{{range_start(default(params.range, '{$defaultRange}'))}}"],
        ]];

        return [
            'filter' => $ownFilter === null
                ? $window
                : ['op' => 'and', 'conditions' => [$ownFilter, $window]],
        ];
    }

    /**
     * Display decoration for a measure KPI. Fraction-scaled ratios (0..1) get
     * the percentage display format — the renderer multiplies by 100, so 0.967
     * shows as 96.7%. Values already on the 0-100 scale must NOT get that
     * format (it would show 9670%); they stay plain and carry their unit on
     * the caption instead ("promedio del periodo · %"), as do durations
     * ("mediana del periodo · min") and currency (its own display format).
     *
     * @param  array<string, mixed>  $field
     * @param  array<string, array{values: list<mixed>, distinct: int, null_rate: float, all_equal: bool}>  $stats
     * @return array{format?: string, unit?: string}
     */
    private function kpiDisplay(array $field, array $stats): array
    {
        if (($field['type'] ?? '') === 'currency') {
            return ['format' => 'currency'];
        }

        $values = $stats[$field['id'] ?? '']['values'] ?? [];
        if ($this->semantics->percentScale($field, $values) === 'fraction') {
            return ['format' => 'percentage'];
        }

        $unit = $this->semantics->unitOf($field);

        return $unit !== null ? ['unit' => $unit] : [];
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
    private function suggestCharts(string $grain, ?array $dateField, array $categoricals, array $numerics, array $measureTypes, array $stats, bool $es, array $object = [], array $promptTopics = []): array
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
            $requested = $this->requestedMeasure($numerics, $promptTopics);
            if ($grain === SemanticProfile::GRAIN_RAW) {
                if ($requested !== null) {
                    // The user asked for this measure — its AVERAGE over time is
                    // the trend they want, not a raw row count. (An "nps" board
                    // over a ticket LIST charts avg(nps_score), not ticket volume.)
                    $trend['aggregation'] = ($measureTypes[$requested['id']] ?? '') === SemanticProfile::MEASURE_ADDITIVE ? 'sum' : 'avg';
                    $trend['y_field_id'] = $requested['id'];
                    $trend['label'] = ($es ? 'Evolución de ' : 'Evolution of ').Str::lower((string) ($requested['name'] ?? $requested['slug']));
                } elseif ($this->isCappedSample($object)) {
                    // A capped sample counted over time is the sampling window in
                    // disguise (older buckets read empty, the newest full) — skip
                    // the volume line rather than ship a misleading trend.
                    $trend = null;
                } else {
                    $trend['aggregation'] = 'count';
                    $trend['label'] = $es ? 'Volumen en el tiempo' : 'Volume over time';
                }
            } else {
                // The measure the user means is usually the one the OBJECT is
                // named after: nps_time_series → nps_score, not its first
                // additive (a prod NPS board charted `responses` and the
                // requested score never rendered). Topical ratio first, then
                // topical additive, then the generic order — and on a series
                // carrying SEVERAL measures (contact rate: tickets,
                // conversaciones, containment), up to three of them each get
                // their own trend: one line was wasting the whole story.
                $pick = $this->leadMeasure($object, $numerics, $measureTypes, $promptTopics);
                if ($pick === null) {
                    $trend = null;
                } else {
                    $candidates = [$pick];
                    $identities = [$this->measureIdentity((string) ($pick[0]['slug'] ?? ''), $pick[1])];
                    // More measures, most-varied additives first, then ratios.
                    $pool = collect($additives)
                        ->sortByDesc(fn (array $f): int => $stats[$f['id']]['distinct'] ?? 0)
                        ->map(fn (array $f): array => [$f, 'sum'])
                        ->concat(collect($ratios)->map(fn (array $f): array => [$f, 'avg']));
                    foreach ($pool as [$field, $agg]) {
                        if (count($candidates) >= 3) {
                            break;
                        }
                        $identity = $this->measureIdentity((string) ($field['slug'] ?? ''), $agg);
                        if ($this->isDuplicateIdentity($identity, $identities)) {
                            continue;
                        }
                        $candidates[] = [$field, $agg];
                        $identities[] = $identity;
                    }

                    // Vary the form so the lint's max-2-per-type holds:
                    // line, area, then bar-over-time.
                    $trendTypes = ['line', 'area', 'bar'];
                    foreach ($candidates as $i => [$field, $agg]) {
                        if (count($charts) >= self::MAX_CHARTS) {
                            break;
                        }
                        $charts[] = [
                            ...$trend,
                            'chart_type' => $trendTypes[$i] ?? 'line',
                            'aggregation' => $agg,
                            'y_field_id' => $field['id'],
                            'label' => ($es ? 'Evolución de ' : 'Evolution of ').Str::lower((string) ($field['name'] ?? $field['slug'])),
                        ];
                    }
                    $trend = null; // already emitted
                }
            }
            if ($trend !== null) {
                $charts[] = $trend;
            }
        }

        // 2)+3) Concentration and statistics-per-dimension — order picked by
        //    grain: on a DIMENSION breakdown carrying pre-computed statistics
        //    (resolution time by category), the statistic IS the story, so
        //    "avg_minutes por key" leads and the volume donut follows. On
        //    every other shape, concentration first as before.
        if ($grain === SemanticProfile::GRAIN_DIMENSION) {
            $this->appendStatisticCharts($charts, $statistics, $categoricals, $es);
        }

        // Concentration: breakdowns per categorical, form chosen by REAL
        // cardinality (donut needs few slices; many go horizontal, capped).
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

        if ($grain !== SemanticProfile::GRAIN_DIMENSION) {
            $this->appendStatisticCharts($charts, $statistics, $categoricals, $es);
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

        // 5) Stacked composition over time needs record-level rows — and a
        //    source that returns them ALL. Over a recency-capped sample its
        //    per-week counts are the sampling window, not real composition.
        if ($grain === SemanticProfile::GRAIN_RAW && $dateField !== null && $categoricals !== [] && ! $this->isCappedSample($object) && count($charts) < self::MAX_CHARTS) {
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

        // Last resort — but never an axis-less "chart-shaped number". The old
        // fallback emitted up to 3 bare bar/hbar/radar blocks with no group_by
        // and no x axis; each rendered as ONE aggregated value (and the radar,
        // which needs ≥3 axes, rendered nothing at all). Prod: a weekly series
        // with only 2 sampled buckets skipped its trend, had no real
        // categoricals, and shipped three of those. Honest ladder instead:
        if ($charts === [] && $numerics !== []) {
            $lead = $this->leadMeasure($object, $numerics, $measureTypes, $promptTopics)
                ?? [$numerics[0], ($measureTypes[$numerics[0]['id']] ?? '') === SemanticProfile::MEASURE_ADDITIVE ? 'sum' : 'avg'];
            [$field, $agg] = $lead;
            $label = (string) ($field['name'] ?? $field['slug']);
            $bucketLabel = $this->bucketLabelField($object['fields'] ?? []);

            if ($dateField !== null && $bucketLabel !== null) {
                // A time axis exists but had too few buckets for a line — one
                // bar per period IS the right picture (few periods, few bars).
                // The bucket-label column is excluded from breakdowns precisely
                // because it re-plots the trend; here that's exactly the point.
                $charts[] = [
                    'label' => $label.($es ? ' por periodo' : ' by period'),
                    'chart_type' => 'bar',
                    'aggregation' => $agg,
                    'y_field_id' => $field['id'],
                    'group_by_field_id' => $bucketLabel['id'],
                    'limit' => self::BREAKDOWN_LIMIT,
                ];
            } elseif ($dateField !== null) {
                $charts[] = [
                    'label' => $label.($es ? ' por día' : ' by day'),
                    'chart_type' => 'bar',
                    'aggregation' => $agg,
                    'y_field_id' => $field['id'],
                    'x_field_id' => $dateField['id'],
                    'bucket' => 'day',
                ];
            } elseif ($grain === SemanticProfile::GRAIN_RAW) {
                // No axis anywhere on raw rows: a box of the lead measure is a
                // legitimate distribution view (spread beats a single number).
                $charts[] = [
                    'label' => ($es ? 'Distribución de ' : 'Distribution of ').Str::lower($label),
                    'chart_type' => 'box',
                    'aggregation' => 'avg',
                    'y_field_id' => $field['id'],
                ];
            } else {
                // Truly nothing to slice by: ONE deliberate single-value bar —
                // never three, never a radar.
                $charts[] = [
                    'label' => $label,
                    'chart_type' => 'bar',
                    'aggregation' => $agg,
                    'y_field_id' => $field['id'],
                ];
            }
        }

        // Defence in depth: no chart leaves here axis-less (no x, no group_by)
        // unless it's a box distribution — except the single deliberate
        // fallback above when it's ALL there is (a chartless spec won't compile).
        $withAxis = array_values(array_filter(
            $charts,
            fn (array $c): bool => isset($c['x_field_id']) || isset($c['group_by_field_id']) || ($c['chart_type'] ?? '') === 'box',
        ));

        return $withAxis !== [] ? $withAxis : array_slice($charts, 0, 1);
    }

    /**
     * Statistics per dimension: shown, never folded. avg over one row per
     * group is the identity, so the number rendered IS the value. Appended
     * before or after the concentration breakdowns depending on grain.
     *
     * @param  list<array<string, mixed>>  $charts
     * @param  list<array<string, mixed>>  $statistics
     * @param  list<array<string, mixed>>  $categoricals
     */
    private function appendStatisticCharts(array &$charts, array $statistics, array $categoricals, bool $es): void
    {
        if ($statistics === [] || $categoricals === []) {
            return;
        }

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

    /**
     * The measure that carries the object's story: its topical ratio first (an
     * object literally named contact_rate leads with the rate), then the
     * topical additive, then the first additive, then the first ratio. Null
     * when none qualifies. Returns [field, aggregation].
     *
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $numerics
     * @param  array<string, string>  $measureTypes
     * @param  list<string>  $promptTopics
     * @return array{0: array<string, mixed>, 1: string}|null
     */
    private function leadMeasure(array $object, array $numerics, array $measureTypes, array $promptTopics): ?array
    {
        $ofType = fn (array $pool, string $type): ?array => collect($pool)->first(
            fn (array $f): bool => ($measureTypes[$f['id']] ?? '') === $type,
        );
        $topical = $this->topicalMeasures($object, $numerics, $promptTopics);

        $pick = collect([
            [$ofType($topical, SemanticProfile::MEASURE_RATIO), 'avg'],
            [$ofType($topical, SemanticProfile::MEASURE_ADDITIVE), 'sum'],
            [$ofType($numerics, SemanticProfile::MEASURE_ADDITIVE), 'sum'],
            [$ofType($numerics, SemanticProfile::MEASURE_RATIO), 'avg'],
        ])->first(fn (array $c): bool => $c[0] !== null);

        return $pick === null ? null : [$pick[0], $pick[1]];
    }

    /**
     * The bucket-label STRING column of a time series (period_label,
     * bucket_label, semana…) — the time axis in a string costume. Excluded
     * from breakdowns, but the correct axis for a bar-per-period fallback.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>|null
     */
    private function bucketLabelField(array $fields): ?array
    {
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            if (($field['type'] ?? '') === 'string'
                && preg_match('/label|bucket|period|semana|week/i', (string) ($field['slug'] ?? '')) === 1) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Insight cards: correct variants + live computes, and — when sampled rows
     * exist — bodies NARRATED with real numbers from the computed facts
     * (FactNarrator). Bank-first compiles the board BEFORE the model gates, so
     * these bodies are what ships when the model can't answer; the semantic
     * gate rewrites them only when it actually responds.
     *
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $categoricals
     * @param  list<array<string, mixed>>  $booleans
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function suggestInsights(array $object, array $categoricals, array $booleans, bool $es, array $rows = []): array
    {
        $objectId = $object['id'] ?? null;
        $insights = [[
            'variant' => 'conclusion',
            'title' => $es ? 'Volumen del periodo' : 'Period volume',
            'body' => $es
                ? 'Registros dentro de la ventana seleccionada — compara contra el periodo anterior para leer la tendencia.'
                : 'Records inside the selected window — compare with the previous period to read the trend.',
            'metric_label' => $es ? 'registros' : 'records',
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
                'metric_label' => $es ? 'casos' : 'cases',
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

        // Stamp each card with a DISTINCT real number from the sampled rows —
        // a board's conclusions carry figures from birth, model or no model.
        if ($rows !== []) {
            $insights = $this->narrator->narrate($insights, $this->factsBuilder->build($object, $rows));
        }

        return $insights;
    }
}
