<?php

namespace App\Services\Analyst;

use App\Models\App;
use App\Models\User;
use App\Services\Express\ComputedFactsBuilder;
use App\Services\Express\SemanticProfile;
use App\Services\Records\FieldPaths;
use App\Services\Records\InMemoryRowFilter;
use App\Services\Records\ObjectRowSource;
use Illuminate\Support\Str;

/**
 * The analyst, as a service any surface can ask.
 *
 * It reads an app's real data and returns the analyses worth having — each
 * grounded in a computed fact ("80% del backlog en 4 motivos"), ranked by how
 * loud the data is and how central the measure is to the business. It knows
 * nothing about WHERE its findings will land: a Finding carries the analysis
 * (the fact, the sentence, the chart or insight spec, a preview), and the
 * calling surface decides what to make of it — a manifest block in the App
 * Builder, a slide in a deck, a message from an agent.
 *
 * Dedup is by SEMANTIC key, not object id, and the surface passes in what it
 * ALREADY shows ($exclude) rather than handing over a page: the core has no
 * notion of a page, a block, or a cache.
 *
 * Deterministic end to end — facts, candidates, scores, previews and gaps are
 * all arithmetic over the sampled rows. The only model in the pipeline is
 * {@see RecommendationNarrator}, which may reorder and reword but never invents
 * a number, and is off by default. This is the honest floor an AI refines.
 */
class AnalystCore
{
    /** How many rows to sample per object for the facts. */
    private const SAMPLE = 500;

    /** Findings returned by default — a surface may ask for fewer or more. */
    public const DEFAULT_MAX = 5;

    /** A sankey with more nodes than this per side is a hairball, not a diagram. */
    private const FLOW_MAX_NODES = 8;

    /** Beyond this many parts, a stacked column is a stripe pattern. */
    private const COMPOSITION_MAX_PARTS = 6;

    /** Records a category needs before its quartiles mean anything. */
    private const SPREAD_MIN_PER_CATEGORY = 5;

    /** History needed before a seasonal read is honest (~18 months). */
    private const SEASONALITY_MIN_DAYS = 540;

    /** Observations needed before "your best period" is a benchmark and not a fluke. */
    private const BENCHMARK_MIN_POINTS = 6;

    /** Points a benchmark must sit above today's level to be worth closing. */
    private const BENCHMARK_MIN_GAP = 2.0;

    /** Rows carrying BOTH dates before a cohort read is worth attempting. */
    private const COHORT_MIN_ROWS = 20;

    /** The share of rows where the activity must actually follow the start. */
    private const COHORT_MIN_FOLLOWS = 0.9;

    /** Intakes needed before "cohorts" means anything — one camada is a total. */
    private const COHORT_MIN_INTAKES = 3;

    /** How unique a column must be to be an identity rather than a segment. */
    private const COHORT_ENTITY_UNIQUENESS = 0.3;

    public function __construct(
        private ObjectRowSource $rows,
        private ComputedFactsBuilder $facts,
        private SemanticProfile $semantics,
        private DomainClassifier $domain,
        private RecommendationNarrator $narrator,
        private DataQualityCheck $quality,
        private CrossSourceAnalyzer $crossSource,
        private DerivedMetricProposer $derived,
        private AnomalyFinder $anomalies,
    ) {}

    /**
     * Analyse every object the app carries and return the findings worth adding.
     *
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $exclude  semantic keys the calling surface already shows
     * @return array{domain: array{sector: string, label: string}, sources: int, total_rows: int, findings: list<array<string, mixed>>, gaps: list<array{text: string}>, data_quality: list<array{level: string, text: string}>, sources_detail: list<array<string, mixed>>, source_suggestions: list<array<string, mixed>>}
     */
    public function analyze(
        App $app,
        array $manifest,
        ?User $actor,
        string $lang = 'es',
        array $exclude = [],
        int $max = self::DEFAULT_MAX,
    ): array {
        $es = $lang !== 'en';
        // Every object the app carries, native or connected — ObjectRowSource
        // resolves either kind to rows.
        $objects = array_values(array_filter(
            $manifest['objects'] ?? [],
            fn ($o): bool => is_array($o) && isset($o['id']),
        ));
        $domain = $this->domain->classify($objects, $lang);
        $names = SemanticKey::fieldNames($manifest);
        // A generic dimension field ("Key") named the same across sources hides
        // WHICH dimension it is — the object's distinguishing suffix does.
        $hints = SemanticKey::objectHints($manifest);
        $shown = array_fill_keys($exclude, true);

        $candidates = [];
        $totalRows = 0;
        $factsByObject = [];
        foreach ($objects as $object) {
            try {
                $rows = $this->rows->sample($app, $object, $manifest, $actor, self::SAMPLE);
                if ($rows === []) {
                    continue;
                }
                $totalRows += count($rows);
                $facts = $this->facts->build($object, $rows);
                $factsByObject[$object['id']] = ['object' => $object, 'rows' => $rows, 'facts' => $facts];

                $connected = FieldPaths::isConnected($object);
                foreach ($this->candidatesFor($object, $rows, $facts, $domain, $es) as $c) {
                    $semKey = SemanticKey::forChart($object['id'], $c['chart'], $names, $hints);
                    if (isset($shown[$semKey])) {
                        continue; // the surface already shows this analysis
                    }
                    $c['identity'] = $this->identityOf($object['id'], $c['chart']);
                    $c['semantic_key'] = $semKey;
                    // How many rows the rendered chart must fetch depends on what
                    // backs it — see FindingBlock.
                    $c['connected'] = $connected;
                    // Key candidates by the SEMANTIC cut too, so two overlapping
                    // sources don't both propose "the same chart".
                    $candidates[$semKey] = $this->rankBest($candidates[$semKey] ?? null, $c);
                }
            } catch (\Throwable) {
                continue; // one malformed object never sinks the whole analysis
            }
        }

        // Cross-source findings: the join no single chart shows (volume in one
        // source vs performance in another over a shared dimension).
        foreach ($this->crossSource->analyze($factsByObject, $names, $hints, $es) as $f) {
            $key = 'cross|'.($f['dim'] ?? '');
            $f['identity'] = $key;
            $f['semantic_key'] = $key;
            $f['score'] = $f['base'] + ($f['flag'] !== null ? 6 : 0);
            $candidates[$key] = $f;
        }

        // Derived metrics: ratios the board doesn't carry (reopen rate…).
        foreach ($this->derived->analyze($factsByObject, $es) as $f) {
            $key = 'derived|'.($f['metric'] ?? '');
            $f['identity'] = $key;
            $f['semantic_key'] = $key;
            $f['score'] = $f['base'];
            $candidates[$key] = $f;
        }

        // Anomalies: the day something happened. The trend chart draws the spike;
        // this is the sentence that names it.
        foreach ($this->anomalies->analyze($factsByObject, $es) as $f) {
            $key = 'anomaly|'.($f['measure'] ?? '');
            $f['identity'] = $key;
            $f['semantic_key'] = $key;
            $f['score'] = $f['base'] + ($f['flag'] !== null ? 6 : 0);
            $candidates[$key] = $f;
        }

        $findings = collect($candidates)
            ->sortByDesc('score')
            ->take($max)
            ->values()
            // A surface-independent id, so narration (and any caller) can address
            // a finding without a page or a cache in the picture.
            ->map(fn (array $c): array => ['id' => substr(sha1($c['identity']), 0, 16)] + $c)
            ->all();

        // AI refines: reorders + rewords in the business voice, keeping the
        // numbers. Off by default (config-gated); passes through untouched.
        $findings = $this->narrator->narrate(
            $findings,
            ['sector' => $domain['sector'], 'label' => $domain['label']],
            $actor,
            $lang,
            $app->id,
        );

        return [
            'domain' => ['sector' => $domain['sector'], 'label' => $domain['label']],
            'sources' => count($factsByObject),
            'total_rows' => $totalRows,
            'findings' => $findings,
            'gaps' => $this->gaps($factsByObject, $shown, $domain, $es),
            'data_quality' => $this->quality->run($factsByObject, $es),
            'sources_detail' => array_map(
                fn (array $e) => $this->sourceDetail($e['object'], count($e['rows']), $es),
                array_values($factsByObject),
            ),
            'source_suggestions' => $this->domain->sourceSuggestions($domain, $es ? 'es' : 'en'),
        ];
    }

    /**
     * A plain-language read of what a source provides — its measures and the
     * dimensions it can be broken down by, in business terms.
     *
     * @param  array<string, mixed>  $object
     * @return array{name: string, rows: int, measures: list<string>, dimensions: list<string>}
     */
    private function sourceDetail(array $object, int $rowCount, bool $es): array
    {
        $fields = array_values(array_filter($object['fields'] ?? [], 'is_array'));
        $measures = collect($fields)
            ->filter(fn (array $f): bool => in_array($f['type'] ?? '', ['number', 'currency'], true))
            ->map(fn (array $f): string => (string) ($f['name'] ?? $f['slug']))
            ->take(6)->values()->all();
        $dimensions = collect($fields)
            ->filter(fn (array $f): bool => in_array($f['type'] ?? '', ['string', 'single_select', 'date', 'datetime'], true)
                && preg_match('/label|bucket|_id$|^id$/i', (string) ($f['slug'] ?? '')) !== 1)
            ->map(fn (array $f): string => (string) ($f['name'] ?? $f['slug']))
            ->take(6)->values()->all();

        return [
            'name' => (string) ($object['name'] ?? $object['slug'] ?? ''),
            'rows' => $rowCount,
            'measures' => $measures,
            'dimensions' => $dimensions,
        ];
    }

    // -- candidate generation ------------------------------------------------

    /**
     * Every analysis this object's data can honestly back, each with the fact
     * that justifies it. Legality is enforced through SemanticProfile, so a
     * candidate never proposes an illegal aggregation or a pie on 500 values.
     *
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $facts
     * @param  array{sector: string, label: string, headline: list<string>}  $domain
     * @return list<array<string, mixed>>
     */
    private function candidatesFor(array $object, array $rows, array $facts, array $domain, bool $es): array
    {
        $out = [];
        $fields = array_values(array_filter($object['fields'] ?? [], 'is_array'));
        $byName = fn (string $needle) => collect($fields)->first(
            fn (array $f): bool => str_contains(Str::lower(Str::ascii((string) ($f['name'] ?? '').' '.(string) ($f['slug'] ?? ''))), $needle)
        );
        $nameOf = fn (?array $f) => $f === null ? '' : (string) ($f['name'] ?? $f['slug']);
        $objName = (string) ($object['name'] ?? $object['slug'] ?? '');
        // Business relevance: an analysis whose measure/dimension is a headline
        // concept for the detected domain (FCR, backlog, reason… for support)
        // ranks above an equally-loud but peripheral one.
        //
        // But an unrecognised domain has NO headline terms, so this bonus was
        // always 0 there and the ranking silently collapsed to kind-precedence —
        // every analysis of an unfamiliar business looked equally relevant, which
        // is the same as having no opinion at all. So when the domain lexicon has
        // nothing to say, the DATA does: what a source is named after is what it
        // is about ("Tickets by reason" → tickets, reason), and a measure or
        // dimension the source is named after outranks an incidental column.
        $head = fn (string ...$names): int => collect($names)->contains(
            fn (string $n): bool => $this->domain->isHeadline($domain, $n)
                || $this->isCentralTo($objName, $n)
        ) ? 12 : 0;

        // 1) PARETO — the strongest read: the measure concentrates in few
        // categories. The fact carries "N de M concentran P%".
        if (isset($facts['concentracion'])) {
            $c = $facts['concentracion'];
            $dim = $byName(Str::lower(Str::ascii($c['dimension'])));
            $measure = $byName(Str::lower(Str::ascii($c['measure'])));
            if ($dim && $measure) {
                $out[] = [
                    'kind' => 'pareto',
                    'kicker' => ($es ? 'Pareto · ' : 'Pareto · ').Str::upper(Str::limit((string) $c['dimension'], 14, '')),
                    'title' => $es ? Str::ucfirst((string) $c['measure']).' se concentra' : Str::ucfirst((string) $c['measure']).' concentrates',
                    'why' => $es
                        ? "{$c['top']} de {$c['total_categorias']} ".Str::lower((string) $c['dimension'])." concentran el {$c['pct']}% del total. Un Pareto lo pone en evidencia."
                        : "{$c['top']} of {$c['total_categorias']} ".Str::lower((string) $c['dimension'])." carry {$c['pct']}% of the total. A Pareto makes it obvious.",
                    'chart' => array_filter([
                        'label' => ($es ? 'Pareto de ' : 'Pareto of ').Str::lower((string) $c['dimension']).' · '.$objName,
                        'chart_type' => 'pareto',
                        'group_by_field_id' => $dim['id'],
                        'y_field_id' => $measure['id'],
                        'aggregation' => 'sum',
                        'description' => Str::ucfirst(Str::lower((string) $c['measure']).($es ? ' por ' : ' by ').Str::lower((string) $c['dimension']).($es ? ', con % acumulado.' : ', with cumulative %.')),
                    ]),
                    'preview' => ['kind' => 'pareto', 'values' => $this->breakdownValues($object, $rows, $dim, $measure, 10)],
                    'base' => 100 + $head((string) $c['dimension'], (string) $c['measure']),
                    'flag' => null,
                ];
            }
        }

        // 2) TREND — a dated measure moving with a real slope. Flag it HOT when
        // it also jumped versus the previous period.
        if (isset($facts['tendencia'])) {
            $t = $facts['tendencia'];
            $date = collect($fields)->first(fn (array $f): bool => in_array($f['type'] ?? '', ['date', 'datetime'], true));
            $measure = $byName(Str::lower(Str::ascii($t['measure'])));
            if ($date && $measure) {
                $pop = $facts['vs_periodo_anterior']['measures'][$t['measure']]['delta_pct'] ?? null;
                $sign = $t['pendiente_pct'] >= 0 ? '+' : '';
                $flag = ($pop !== null && abs($pop) >= 15)
                    ? ['tone' => 'hot', 'text' => ($pop >= 0 ? '+' : '').$pop.'% vs. '.($es ? 'periodo' : 'period')]
                    : null;
                $series = $this->weeklyValues($object, $rows, $date, $measure);
                // Run-rate: a declining measure heading to zero gets a concrete
                // ETA — the projection an analyst leads with.
                $runRate = $this->weeksToZero($series);
                $projection = $runRate !== null
                    ? ($es ? " Al ritmo actual, ~{$runRate} semanas para llegar a 0." : " At this pace, ~{$runRate} weeks to reach 0.")
                    : '';
                $out[] = [
                    'kind' => 'trend',
                    'kicker' => $es ? 'Tendencia · Semanal' : 'Trend · Weekly',
                    'title' => $es ? Str::ucfirst((string) $t['measure']).' en el tiempo' : Str::ucfirst((string) $t['measure']).' over time',
                    'why' => ($es
                        ? Str::ucfirst((string) $t['measure'])." se mueve {$sign}{$t['pendiente_pct']}% por {$t['cadencia']}".($pop !== null ? " ({$sign}{$pop}% vs. periodo anterior)" : '').'. Vale seguirlo semana a semana.'
                        : Str::ucfirst((string) $t['measure'])." is moving {$sign}{$t['pendiente_pct']}% per {$t['cadencia']}".($pop !== null ? " ({$sign}{$pop}% vs. previous period)" : '').'. Worth tracking weekly.').$projection,
                    'chart' => array_filter([
                        'label' => ($es ? 'Evolución de ' : 'Trend of ').Str::lower((string) $t['measure']),
                        'chart_type' => 'area',
                        'x_field_id' => $date['id'],
                        'y_field_id' => $measure['id'],
                        'aggregation' => 'sum',
                        'bucket' => 'week',
                        'description' => Str::ucfirst(($es ? 'Evolución semanal de ' : 'Weekly trend of ').Str::lower((string) $t['measure']).'.'),
                    ]),
                    'preview' => ['kind' => 'area', 'values' => $series],
                    'base' => 88 + min(10, (int) round(abs((float) $t['pendiente_pct']) / 3)) + $head((string) $t['measure']),
                    'flag' => $flag,
                ];
            }
        }

        // 2b) VOLUME vs RATE — the executive chart: how much of it there is
        // (bars, left axis) against how well it goes (line, right axis), over the
        // same dimension. It answers "where does improving actually pay?", which
        // neither series answers alone — and it deliberately shares the Pareto's
        // semantic key, because it is the same cut told better: when a rate
        // exists, the board should get this instead, never both.
        $volume = collect($fields)->first(fn (array $f): bool => in_array($f['type'] ?? '', ['number', 'currency'], true)
            && $this->semantics->measureTypeIn($object, $rows, $f) === SemanticProfile::MEASURE_ADDITIVE);
        $rate = collect($fields)->first(fn (array $f): bool => in_array($f['type'] ?? '', ['number', 'currency'], true)
            && $this->semantics->measureTypeIn($object, $rows, $f) === SemanticProfile::MEASURE_RATIO);
        $comboDim = isset($facts['concentracion'])
            ? $byName(Str::lower(Str::ascii((string) $facts['concentracion']['dimension'])))
            : (isset($facts['top_values']) ? $byName(Str::lower(Str::ascii((string) array_key_first($facts['top_values'])))) : null);

        if ($volume && $rate && $comboDim) {
            $pairs = $this->volumeAgainstRate($object, $rows, $comboDim, $volume, $rate, 8);
            if ($pairs['values'] !== []) {
                $dimName = $nameOf($comboDim);
                $out[] = [
                    'kind' => 'combo',
                    'kicker' => ($es ? 'Volumen vs. tasa · ' : 'Volume vs. rate · ').Str::upper(Str::limit($dimName, 12, '')),
                    'title' => $es
                        ? Str::ucfirst(Str::lower($nameOf($volume))).' y '.Str::lower($nameOf($rate)).' por '.Str::lower($dimName)
                        : Str::ucfirst(Str::lower($nameOf($volume))).' and '.Str::lower($nameOf($rate)).' by '.Str::lower($dimName),
                    'why' => $es
                        ? 'El volumen y '.Str::lower($nameOf($rate)).' rara vez coinciden: en un eje doble se ve de un vistazo dónde hay mucho Y va mal — ahí es donde más rinde mejorar.'
                        : 'Volume and '.Str::lower($nameOf($rate))." rarely line up: on a dual axis you see at a glance where there's a lot AND it goes badly — that's where improving pays most.",
                    'chart' => [
                        'label' => Str::ucfirst(Str::lower($nameOf($volume))).($es ? ' vs. ' : ' vs. ').Str::lower($nameOf($rate)).' · '.$objName,
                        // With `series` present the renderer ignores the top-level
                        // mark and measure — but y_field_id still names the cut, so
                        // this dedupes against the plain breakdown of the same measure.
                        'chart_type' => 'bar',
                        'group_by_field_id' => $comboDim['id'],
                        'y_field_id' => $volume['id'],
                        'aggregation' => 'sum',
                        'series' => [
                            ['type' => 'bar', 'field_id' => $volume['id'], 'aggregation' => 'sum', 'label' => $nameOf($volume), 'axis' => 'left'],
                            ['type' => 'line', 'field_id' => $rate['id'], 'aggregation' => 'avg', 'label' => $nameOf($rate), 'axis' => 'right'],
                        ],
                        'description' => $es
                            ? Str::ucfirst(Str::lower($nameOf($volume))).' en barras, '.Str::lower($nameOf($rate)).' en línea sobre el eje derecho.'
                            : Str::ucfirst(Str::lower($nameOf($volume))).' as bars, '.Str::lower($nameOf($rate)).' as a line on the right axis.',
                    ],
                    'preview' => ['kind' => 'combo', 'values' => $pairs['values'], 'line' => $pairs['line']],
                    // Above a Pareto: same cut, but it also says how well it goes.
                    'base' => 104 + $head($dimName, $nameOf($volume), $nameOf($rate)),
                    'flag' => null,
                ];
            }
        }

        // 3) CORRELATION — two measures that move together. The classic analyst
        // read, and the one no single-measure chart can make: it lives in the
        // relationship, so it needs a scatter.
        if (isset($facts['correlacion'])) {
            $c = $facts['correlacion'];
            $strong = abs((float) $c['r']) >= 0.8;
            $together = $c['direction'] === 'up';
            $out[] = [
                'kind' => 'correlation',
                'kicker' => ($es ? 'Correlación · r=' : 'Correlation · r=').$c['r'],
                'title' => $es
                    ? Str::ucfirst((string) $c['x']).' vs. '.Str::lower((string) $c['y'])
                    : Str::ucfirst((string) $c['x']).' vs. '.Str::lower((string) $c['y']),
                'why' => $es
                    ? Str::ucfirst((string) $c['x']).' y '.Str::lower((string) $c['y']).($together ? ' suben juntas' : ' se mueven en sentido contrario')." (r = {$c['r']} sobre {$c['n']} registros). Un scatter muestra la relación — y dónde se rompe."
                    : Str::ucfirst((string) $c['x']).' and '.Str::lower((string) $c['y']).($together ? ' rise together' : ' move in opposite directions')." (r = {$c['r']} across {$c['n']} records). A scatter shows the relationship — and where it breaks.",
                'chart' => [
                    'label' => Str::ucfirst((string) $c['x']).($es ? ' vs. ' : ' vs. ').Str::lower((string) $c['y']).' · '.$objName,
                    'chart_type' => 'scatter',
                    'x_field_id' => $c['x_id'],
                    'y_field_id' => $c['y_id'],
                    // The renderer plots raw points and ignores this, but the
                    // manifest schema requires an aggregation on every chart.
                    'aggregation' => 'sum',
                    'description' => $es
                        ? 'Cada punto es un registro: '.Str::lower((string) $c['x']).' contra '.Str::lower((string) $c['y']).'.'
                        : 'Each point is a record: '.Str::lower((string) $c['x']).' against '.Str::lower((string) $c['y']).'.',
                ],
                'preview' => ['kind' => 'scatter', 'points' => $this->scatterPoints($object, $rows, $c['x_id'], $c['y_id'])],
                // A strong relationship outranks a breakdown and rivals a trend:
                // it says something the board cannot currently say at all.
                'base' => 90 + min(10, (int) round((abs((float) $c['r']) - 0.6) * 25)) + $head((string) $c['x'], (string) $c['y']),
                'flag' => $strong ? ['tone' => 'hot', 'text' => 'r = '.$c['r']] : null,
            ];
        }

        // 4) GAUGE — a ratio measure read against a benchmark that EXISTS.
        //
        // This used to invent one: `$target = 80` for anything on a 0-100 scale.
        // We told people they were "3.2 points from target" against a goal we made
        // up, which is the one thing an analyst may never do. A gauge now needs a
        // benchmark it can defend — a target the source declares, or the
        // organisation's own best period — and when there is neither it reports
        // the level and says nothing about a goal.
        foreach ($fields as $f) {
            if (! in_array($f['type'] ?? '', ['number', 'currency'], true)) {
                continue;
            }
            if ($this->semantics->measureTypeIn($object, $rows, $f) !== SemanticProfile::MEASURE_RATIO) {
                continue;
            }
            $numeric = $this->numericValuesOf($object, $rows, $f);
            if ($numeric === []) {
                continue;
            }
            $avg = array_sum($numeric) / count($numeric);
            // A ratio stored 0-1 becomes a %; one already 0-100 stays as-is.
            $scale = $this->semantics->percentScale($f, $numeric) === 'fraction' ? 100 : 1;
            $value = round($avg * $scale, 1);
            $benchmark = $this->benchmarkFor($object, $rows, $fields, $f, $numeric, $scale);

            if ($benchmark === null) {
                // No defensible goal: state the level, claim nothing. The gauge's
                // ceiling is the scale's own (100%), not a target.
                $out[] = [
                    'kind' => 'gauge',
                    'kicker' => ($es ? 'Nivel · ' : 'Level · ').Str::upper(Str::limit($nameOf($f), 12, '')),
                    'title' => $nameOf($f).($es ? ' hoy' : ' today'),
                    'why' => $es
                        ? "{$nameOf($f)} promedia {$value}%. No hay meta declarada ni un mejor histórico contra el que compararlo — el medidor lo deja a la vista; la meta la pones tú."
                        : "{$nameOf($f)} averages {$value}%. There is no declared target and no better past period to compare it against — the gauge puts it in plain sight; the goal is yours to set.",
                    'chart' => [
                        '__gauge' => true,
                        'label' => $nameOf($f),
                        'field_id' => $f['id'],
                        'aggregation' => 'avg',
                        'max_value' => 100,
                        'format' => 'percentage',
                    ],
                    'preview' => ['kind' => 'gauge', 'value' => $value, 'target' => 100],
                    'base' => 76 + $head($nameOf($f)),
                    'flag' => null,
                ];
                break;
            }

            $target = $benchmark['value'];
            $gap = round(abs($target - $value), 1);
            $declared = $benchmark['source'] === 'declared';
            $against = $declared
                ? ($es ? 'la meta de '.$target.'%' : "the {$target}% target")
                : ($es ? 'tu mejor periodo ('.$target.'%)' : "your own best period ({$target}%)");

            $out[] = [
                'kind' => 'gauge',
                'kicker' => ($es ? 'Medidor · ' : 'Gauge · ').Str::upper(Str::limit($nameOf($f), 12, '')),
                'title' => $nameOf($f).($es ? ' contra '.($declared ? 'la meta' : 'tu mejor marca') : ' vs. '.($declared ? 'target' : 'your best')),
                'why' => $es
                    ? "{$nameOf($f)} está en {$value}%, a {$gap} pts de {$against}. ".($declared ? 'Es la meta que trae la fuente.' : 'Ya lo lograste una vez, así que es alcanzable.')
                    : "{$nameOf($f)} is at {$value}%, {$gap} pts from {$against}. ".($declared ? 'That target comes from the source itself.' : 'You have hit it before, so it is reachable.'),
                'chart' => [
                    '__gauge' => true,
                    'label' => ($es ? 'Meta: ' : 'Target: ').$nameOf($f),
                    'field_id' => $f['id'],
                    'aggregation' => 'avg',
                    'max_value' => $target,
                    'format' => 'percentage',
                ],
                'preview' => ['kind' => 'gauge', 'value' => $value, 'target' => $target],
                'base' => 82 + $head($nameOf($f)),
                'flag' => $value < $target ? ['tone' => 'gap', 'text' => $gap.' pts '.($es ? 'a la meta' : 'to target')] : null,
            ];
            break; // one gauge is enough per object
        }

        // 5) FLOW — two categoricals that co-occur: reason → owner, channel →
        // outcome, stage → stage. Where the work GOES, which no single-dimension
        // chart can show. A sankey with too many nodes is a hairball, so both
        // ends must be small enough to read.
        $dims = collect($fields)
            ->filter(fn (array $f): bool => in_array($f['type'] ?? '', ['string', 'single_select'], true)
                && preg_match('/label|bucket|period|semana|week|_id$|^id$/i', (string) ($f['slug'] ?? '')) !== 1)
            ->filter(fn (array $f): bool => $this->distinctCount($object, $rows, $f) >= 2
                && $this->distinctCount($object, $rows, $f) <= self::FLOW_MAX_NODES)
            ->values();

        if ($dims->count() >= 2) {
            $source = $dims[0];
            $target = $dims[1];
            $out[] = [
                'kind' => 'flow',
                'kicker' => ($es ? 'Flujo · ' : 'Flow · ').Str::upper(Str::limit($nameOf($source), 10, '')),
                'title' => $es
                    ? Str::ucfirst(Str::lower($nameOf($source))).' → '.Str::lower($nameOf($target))
                    : Str::ucfirst(Str::lower($nameOf($source))).' → '.Str::lower($nameOf($target)),
                'why' => $es
                    ? 'Cada '.Str::lower($nameOf($source)).' se reparte entre '.Str::lower($nameOf($target)).', y el reparto no es parejo. Un diagrama de flujo muestra a dónde va cada cosa — y qué camino carga más de lo que debería.'
                    : 'Each '.Str::lower($nameOf($source)).' splits across '.Str::lower($nameOf($target)).", and the split isn't even. A flow diagram shows where everything goes — and which path carries more than it should.",
                'chart' => array_filter([
                    'label' => Str::ucfirst(Str::lower($nameOf($source))).' → '.Str::lower($nameOf($target)).' · '.$objName,
                    'chart_type' => 'sankey',
                    'group_by_field_id' => $source['id'],
                    'series_field_id' => $target['id'],
                    'y_field_id' => $volume['id'] ?? null,
                    'aggregation' => $volume !== null ? 'sum' : 'count',
                    'description' => $es
                        ? 'El ancho de cada cinta es el volumen que va de un '.Str::lower($nameOf($source)).' a un '.Str::lower($nameOf($target)).'.'
                        : 'Each ribbon\'s width is the volume flowing from a '.Str::lower($nameOf($source)).' to a '.Str::lower($nameOf($target)).'.',
                ]),
                'preview' => ['kind' => 'bars', 'values' => $this->breakdownValues($object, $rows, $source, $volume, 6)],
                'base' => 74 + $head($nameOf($source), $nameOf($target)),
                'flag' => null,
            ];
        }

        // 6) COMPOSITION over time — what the total is MADE OF, month by month.
        // A trend says the total is rising; this says which part is doing the
        // rising, which is the question that follows it.
        $composeDim = $dims->first(fn (array $f): bool => $this->distinctCount($object, $rows, $f) <= self::COMPOSITION_MAX_PARTS);
        $dateField = collect($fields)->first(fn (array $f): bool => in_array($f['type'] ?? '', ['date', 'datetime'], true));

        if ($composeDim && $dateField && $volume) {
            $out[] = [
                'kind' => 'composition',
                'kicker' => ($es ? 'Composición · ' : 'Composition · ').Str::upper(Str::limit($nameOf($composeDim), 10, '')),
                'title' => $es
                    ? Str::ucfirst(Str::lower($nameOf($volume))).' por '.Str::lower($nameOf($composeDim)).' en el tiempo'
                    : Str::ucfirst(Str::lower($nameOf($volume))).' by '.Str::lower($nameOf($composeDim)).' over time',
                'why' => $es
                    ? 'La tendencia dice que el total se mueve; no dice QUÉ parte lo mueve. Apilado por '.Str::lower($nameOf($composeDim)).' se ve si el crecimiento viene de todos o de uno solo.'
                    : "The trend says the total is moving; it doesn't say WHICH part moves it. Stacked by ".Str::lower($nameOf($composeDim)).', you see whether the growth comes from everything or from one thing.',
                'chart' => [
                    'label' => Str::ucfirst(Str::lower($nameOf($volume))).($es ? ' por ' : ' by ').Str::lower($nameOf($composeDim)),
                    'chart_type' => 'bar',
                    'x_field_id' => $dateField['id'],
                    'series_field_id' => $composeDim['id'],
                    'y_field_id' => $volume['id'],
                    'aggregation' => 'sum',
                    'bucket' => 'month',
                    'stacked' => true,
                    'description' => $es
                        ? 'Cada barra es un mes, partido por '.Str::lower($nameOf($composeDim)).'.'
                        : 'Each bar is a month, split by '.Str::lower($nameOf($composeDim)).'.',
                ],
                'preview' => ['kind' => 'bars', 'values' => $this->weeklyValues($object, $rows, $dateField, $volume)],
                'base' => 80 + $head($nameOf($volume), $nameOf($composeDim)),
                'flag' => null,
            ];
        }

        // 7) DISTRIBUTION — the average is a liar. A box plot shows the spread
        // the mean hides, but only where there are enough records PER category
        // for quartiles to mean anything (a pre-aggregated source has one row per
        // category and nothing to spread).
        $spreadMeasure = collect($fields)->first(fn (array $f): bool => in_array($f['type'] ?? '', ['number', 'currency'], true)
            && $this->semantics->measureTypeIn($object, $rows, $f) !== SemanticProfile::MEASURE_IDENTIFIER);
        $spreadDim = $dims->first(fn (array $f): bool => $this->distinctCount($object, $rows, $f) <= self::COMPOSITION_MAX_PARTS);

        if ($spreadMeasure && $spreadDim) {
            $spread = $this->spreadOf($object, $rows, $spreadDim, $spreadMeasure);
            if ($spread !== null) {
                $out[] = [
                    'kind' => 'distribution',
                    'kicker' => ($es ? 'Dispersión · ' : 'Spread · ').Str::upper(Str::limit($nameOf($spreadMeasure), 10, '')),
                    'title' => $es
                        ? Str::ucfirst(Str::lower($nameOf($spreadMeasure))).': lo que la media esconde'
                        : Str::ucfirst(Str::lower($nameOf($spreadMeasure))).': what the average hides',
                    'why' => $es
                        ? "En «{$spread['category']}» la mediana de ".Str::lower($nameOf($spreadMeasure))." es {$spread['median']}, pero 1 de cada 4 supera {$spread['p75']}. Promediar eso borra justo el caso que duele."
                        : "In \"{$spread['category']}\" the median ".Str::lower($nameOf($spreadMeasure))." is {$spread['median']}, but 1 in 4 exceeds {$spread['p75']}. Averaging that erases exactly the case that hurts.",
                    'chart' => [
                        'label' => ($es ? 'Dispersión de ' : 'Spread of ').Str::lower($nameOf($spreadMeasure)).($es ? ' por ' : ' by ').Str::lower($nameOf($spreadDim)),
                        'chart_type' => 'box',
                        'group_by_field_id' => $spreadDim['id'],
                        'y_field_id' => $spreadMeasure['id'],
                        // The box IS the summary; the renderer ignores this, but
                        // the schema requires an aggregation on every chart.
                        'aggregation' => 'avg',
                        'description' => $es
                            ? 'Mediana, cuartiles y atípicos de '.Str::lower($nameOf($spreadMeasure)).' en cada '.Str::lower($nameOf($spreadDim)).'.'
                            : 'Median, quartiles and outliers of '.Str::lower($nameOf($spreadMeasure)).' within each '.Str::lower($nameOf($spreadDim)).'.',
                    ],
                    'preview' => ['kind' => 'bars', 'values' => $spread['medians']],
                    'base' => 86 + $head($nameOf($spreadMeasure)),
                    'flag' => $spread['skewed'] ? ['tone' => 'hot', 'text' => $es ? 'cola larga' : 'long tail'] : null,
                ];
            }
        }

        // 8) SEASONALITY — the same measure read at a coarser grain. A weekly line
        // cannot show a yearly rhythm; only a span long enough to HAVE one earns
        // this card.
        if ($dateField && $volume) {
            $span = $this->spanDays($object, $rows, $dateField);
            if ($span >= self::SEASONALITY_MIN_DAYS) {
                $out[] = [
                    'kind' => 'seasonality',
                    'kicker' => $es ? 'Estacionalidad · Trimestral' : 'Seasonality · Quarterly',
                    'title' => $es
                        ? Str::ucfirst(Str::lower($nameOf($volume))).' por trimestre'
                        : Str::ucfirst(Str::lower($nameOf($volume))).' by quarter',
                    'why' => $es
                        ? 'Hay '.round($span / 365, 1).' años de historia: suficiente para ver si el negocio tiene estación. La vista semanal no puede mostrarlo — el ritmo anual queda enterrado en el ruido.'
                        : round($span / 365, 1).' years of history: enough to see whether the business has a season. The weekly view cannot show it — a yearly rhythm is buried in the noise.',
                    'chart' => [
                        'label' => Str::ucfirst(Str::lower($nameOf($volume))).($es ? ' por trimestre' : ' by quarter'),
                        'chart_type' => 'bar',
                        'x_field_id' => $dateField['id'],
                        'y_field_id' => $volume['id'],
                        'aggregation' => 'sum',
                        'bucket' => 'quarter',
                        'description' => $es
                            ? 'Cada barra es un trimestre — los picos repetidos son estación, no casualidad.'
                            : 'Each bar is a quarter — repeated peaks are a season, not a coincidence.',
                    ],
                    'preview' => ['kind' => 'bars', 'values' => $this->weeklyValues($object, $rows, $dateField, $volume)],
                    'base' => 78 + $head($nameOf($volume)),
                    'flag' => null,
                ];
            }
        }

        // 9) COHORT — retention. Every other card on the board mixes the people
        // who arrived in March with the people who arrived in June, and a total
        // that mixes them cannot tell you whether the product is getting better
        // at keeping them: a great June hides a leaking March. A cohort table
        // reads each intake from ITS OWN beginning, so the intakes become
        // comparable — which is the only way that question has an answer.
        $cohort = $this->cohortPair($object, $rows, $fields);
        if ($cohort !== null) {
            $entityName = $cohort['entity'] !== null ? Str::lower($nameOf($cohort['entity'])) : ($es ? 'registros' : 'records');
            $out[] = [
                'kind' => 'cohort',
                'kicker' => $es ? 'Cohortes · Retención' : 'Cohorts · Retention',
                'title' => $es
                    ? 'Retención por '.Str::lower($nameOf($cohort['start']))
                    : 'Retention by '.Str::lower($nameOf($cohort['start'])),
                'why' => $es
                    ? "Hay {$cohort['cohorts']} camadas distintas según ".Str::lower($nameOf($cohort['start'])).', y el tablero las suma todas en un mismo total — así una camada que se fuga queda tapada por otra que no. Una tabla de cohortes lee cada una desde su propio mes 0 y muestra cuántos siguen ahí después.'
                    : "There are {$cohort['cohorts']} distinct intakes by ".Str::lower($nameOf($cohort['start'])).", and the board adds them all into one total — so an intake that leaks is hidden by one that doesn't. A cohort table reads each from its own month 0 and shows how many are still there after.",
                'chart' => array_filter([
                    // Not a chart: a pivot. The marker mirrors __gauge.
                    '__pivot' => true,
                    'label' => ($es ? 'Retención de ' : 'Retention of ').$entityName,
                    'group_by_field_id' => $cohort['start']['id'],
                    'bucket' => 'month',
                    'column_field_id' => $cohort['activity']['id'],
                    'column_bucket' => 'month',
                    'y_field_id' => $cohort['entity']['id'] ?? null,
                    // Counting rows counts events; retention counts the entities
                    // that came BACK, and one customer returning twice is one
                    // customer.
                    'aggregation' => $cohort['entity'] !== null ? 'distinct_count' : 'count',
                    'mode' => 'cohort',
                    'description' => $es
                        ? 'Cada fila es la camada que llegó ese mes; cada columna, los meses transcurridos desde entonces.'
                        : 'Each row is the intake of that month; each column, the months elapsed since.',
                ], fn ($v) => $v !== null),
                'preview' => ['kind' => 'bars', 'values' => $cohort['sizes']],
                'base' => 96 + $head($nameOf($cohort['start'])),
                'flag' => null,
            ];
        }

        // 10) BREAKDOWN — a dimension worth splitting the measure by when nothing
        // concentrated (evenly spread reads as a ranking, not a pareto).
        if (! isset($facts['concentracion']) && isset($facts['top_values'])) {
            $topName = (string) array_key_first($facts['top_values']);
            $tv = $facts['top_values'][$topName];
            $dim = $byName(Str::lower(Str::ascii($topName)));
            $measure = collect($fields)->first(fn (array $f): bool => in_array($f['type'] ?? '', ['number', 'currency'], true)
                && $this->semantics->measureTypeIn($object, $rows, $f) === SemanticProfile::MEASURE_ADDITIVE);
            if ($dim) {
                $form = ($tv['distinct'] <= 8) ? 'donut' : 'hbar';
                $out[] = [
                    'kind' => 'breakdown',
                    'kicker' => ($es ? 'Desglose · ' : 'Breakdown · ').Str::upper(Str::limit($topName, 12, '')),
                    'title' => ($es ? 'Por ' : 'By ').Str::lower($topName),
                    'why' => $es
                        ? "«{$tv['top']}» lidera con {$tv['share_pct']}% de {$tv['distinct']} ".Str::lower($topName).'. Un desglose muestra el reparto completo.'
                        : "\"{$tv['top']}\" leads with {$tv['share_pct']}% across {$tv['distinct']} ".Str::lower($topName).'. A breakdown shows the full split.',
                    'chart' => array_filter([
                        'label' => ($es ? 'Por ' : 'By ').Str::lower($topName).' · '.$objName,
                        'chart_type' => $form,
                        'group_by_field_id' => $dim['id'],
                        'y_field_id' => $measure['id'] ?? null,
                        'aggregation' => $measure !== null ? 'sum' : 'count',
                        'description' => Str::ucfirst(Str::lower((string) ($measure['name'] ?? ($es ? 'Registros' : 'Records'))).($es ? ' por ' : ' by ').Str::lower($topName).'.'),
                    ]),
                    'preview' => ['kind' => 'bars', 'values' => $this->breakdownValues($object, $rows, $dim, $measure, 6)],
                    'base' => 68 + $head($topName),
                    'flag' => null,
                ];
            }
        }

        return $out;
    }

    /** Keep the higher-scored of two candidates that map to the same identity. */
    private function rankBest(?array $a, array $b): array
    {
        $b['score'] = $b['base'] + ($b['flag'] !== null ? 6 : 0);

        return ($a === null || $b['score'] > $a['score']) ? $b : $a;
    }

    // -- gap analysis --------------------------------------------------------

    /**
     * What the surface ISN'T showing yet. Deterministic: ratio measures with no
     * gauge, a period-comparison the KPIs never make.
     *
     * @param  array<string, array{object: array<string,mixed>, rows: list<array<string,mixed>>, facts: array<string,mixed>}>  $byObject
     * @param  array<string, true>  $shown
     * @param  array{sector: string, label: string, headline: list<string>}  $domain
     * @return list<array{text: string}>
     */
    private function gaps(array $byObject, array $shown, array $domain, bool $es): array
    {
        $gaps = [];
        foreach ($byObject as $entry) {
            $facts = $entry['facts'];
            $object = $entry['object'];
            $rows = $entry['rows'];
            foreach ($object['fields'] ?? [] as $f) {
                if (! is_array($f) || ! in_array($f['type'] ?? '', ['number', 'currency'], true)) {
                    continue;
                }
                // What the surface ALREADY shows is not a gap. The $shown argument
                // was taken and never read, so the panel would tell you a measure
                // had no gauge while its gauge sat on the board above the chip.
                $name = (string) ($f['name'] ?? '');
                $gaugeKey = 'gauge|'.Str::lower(Str::ascii($name)).'|';
                if (isset($shown[$gaugeKey])) {
                    continue;
                }
                if ($this->semantics->measureTypeIn($object, $rows, $f) === SemanticProfile::MEASURE_RATIO
                    && $this->domain->isHeadline($domain, $name)
                    && count($gaps) < 3) {
                    $gaps[$name] = ['text' => Str::limit($name, 18, '').($es ? ' sin medidor' : ' has no gauge')];
                }
            }
            if (! isset($facts['vs_periodo_anterior']) && count($gaps) < 3) {
                $gaps['__pop'] = ['text' => $es ? 'Sin comparación de periodo' : 'No period comparison'];
            }
        }

        return array_values(array_slice($gaps, 0, 3));
    }

    // -- data helpers --------------------------------------------------------

    /**
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $dim
     * @param  array<string, mixed>|null  $measure
     * @return list<float>
     */
    private function breakdownValues(array $object, array $rows, array $dim, ?array $measure, int $cap): array
    {
        $paths = FieldPaths::forObject($object);
        $dimPath = $paths[$dim['id']] ?? ($dim['slug'] ?? null);
        $numPath = $measure !== null ? ($paths[$measure['id']] ?? ($measure['slug'] ?? null)) : null;
        $sums = [];
        foreach ($rows as $row) {
            $key = data_get($row, $dimPath);
            if (! is_scalar($key) || trim((string) $key) === '') {
                continue;
            }
            $add = $numPath !== null ? data_get($row, $numPath) : 1;
            $sums[(string) $key] = ($sums[(string) $key] ?? 0) + (is_numeric($add) ? (float) $add : 0);
        }
        arsort($sums);

        return array_map(fn ($v) => round((float) $v, 2), array_slice(array_values($sums), 0, $cap));
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $date
     * @param  array<string, mixed>  $measure
     * @return list<float>
     */
    private function weeklyValues(array $object, array $rows, array $date, array $measure): array
    {
        $paths = FieldPaths::forObject($object);
        $datePath = $paths[$date['id']] ?? ($date['slug'] ?? null);
        $numPath = $paths[$measure['id']] ?? ($measure['slug'] ?? null);
        $byWeek = [];
        foreach ($rows as $row) {
            $ts = InMemoryRowFilter::timestamp(data_get($row, $datePath));
            $value = data_get($row, $numPath);
            if ($ts === null || ! is_numeric($value)) {
                continue;
            }
            $byWeek[date('o-\WW', $ts)] = ($byWeek[date('o-\WW', $ts)] ?? 0) + (float) $value;
        }
        ksort($byWeek);

        return array_map(fn ($v) => round((float) $v, 2), array_values(array_slice($byWeek, -16)));
    }

    /**
     * Whether a field is what its source is ABOUT — the data's own answer to
     * "what matters here", for the businesses the domain lexicon has never heard
     * of. A "Tickets by reason" source is about tickets and about reasons; its
     * `updated_at_ms` column is not what it is for.
     *
     * Single-token words are ignored on both sides: matching "de", "by" or "por"
     * would make every column central, which is the same as none being.
     */
    private function isCentralTo(string $objectName, string $fieldName): bool
    {
        $normalise = fn (string $s): array => array_values(array_filter(
            preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($s))) ?: [],
            fn (string $token): bool => mb_strlen($token) >= 4,
        ));

        $subject = $normalise($objectName);
        if ($subject === []) {
            return false;
        }

        foreach ($normalise($fieldName) as $token) {
            if (in_array($token, $subject, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A benchmark this measure can honestly be judged against — or nothing.
     *
     * Two sources, in order of authority:
     *   1. a target the SOURCE declares (a `meta_fcr`, `sla_target`, `objetivo`
     *      column sitting right next to the measure) — the business's own number;
     *   2. the organisation's own best observed period (the 90th percentile of
     *      what it has actually achieved) — not a goal, but a fact: it has done
     *      this before, so it is reachable.
     *
     * A benchmark below or at the current level is not a benchmark, it's a
     * congratulation, and there is nothing to close. Inventing a round number
     * (the old flat 80%) is the one thing this must never do.
     *
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $measure
     * @param  list<float>  $numeric  the measure's sampled values
     * @return array{value: float, source: string}|null
     */
    private function benchmarkFor(array $object, array $rows, array $fields, array $measure, array $numeric, int $scale): ?array
    {
        $current = (array_sum($numeric) / count($numeric)) * $scale;

        // 1) A declared target, sitting in the data next to the measure.
        $targetField = collect($fields)->first(function (array $f) use ($measure): bool {
            if (($f['id'] ?? null) === ($measure['id'] ?? null)) {
                return false;
            }
            if (! in_array($f['type'] ?? '', ['number', 'currency'], true)) {
                return false;
            }

            // A target column says it is a GOAL. It does not merely mention a
            // service level: «sla» names the target AND the attainment of it, so
            // matching it made `confirmation_sla_pct` — a measure in its own
            // right — the declared target of an unrelated one, and the card said
            // "that target comes from the source itself" with total confidence.
            // An invented goal that sounds sourced is worse than the flat 80% this
            // was built to kill.
            return preg_match(
                '/(^|_|\s)(meta|target|objetivo|goal)(_|$|\s)/i',
                Str::lower(Str::ascii((string) ($f['name'] ?? '').' '.(string) ($f['slug'] ?? ''))),
            ) === 1;
        });

        if (is_array($targetField)) {
            $targetValues = $this->numericValuesOf($object, $rows, $targetField);
            if ($targetValues !== []) {
                $declared = round((array_sum($targetValues) / count($targetValues)) * $scale, 1);
                if ($declared > $current) {
                    return ['value' => $declared, 'source' => 'declared'];
                }
            }
        }

        // 2) The best it has actually been. Needs enough history to have a "best"
        // that isn't just the single luckiest row.
        if (count($numeric) < self::BENCHMARK_MIN_POINTS) {
            return null;
        }
        $sorted = $numeric;
        sort($sorted);
        $best = round($this->quantile($sorted, 0.9) * $scale, 1);

        // A best that the average already matches says nothing worth a card.
        return ($best - $current) >= self::BENCHMARK_MIN_GAP
            ? ['value' => $best, 'source' => 'best']
            : null;
    }

    /**
     * The two dates that form a cohort — or nothing.
     *
     * A cohort is not "any two dates". It is a BIRTH and a RETURN: the day someone
     * arrived, and the day they did something afterwards. So the pair only holds
     * if the activity actually follows the start, in almost every row — two dates
     * that cross each other are not an intake and a return, they are just two
     * dates, and a retention table built on them would be a confident answer to a
     * question nobody asked.
     *
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $fields
     * @return array{start: array<string,mixed>, activity: array<string,mixed>, entity: array<string,mixed>|null, cohorts: int, sizes: list<float>}|null
     */
    private function cohortPair(array $object, array $rows, array $fields): ?array
    {
        $dates = collect($fields)
            ->filter(fn (array $f): bool => in_array($f['type'] ?? '', ['date', 'datetime'], true))
            ->values();
        if ($dates->count() < 2) {
            return null;
        }

        $paths = FieldPaths::forObject($object);

        foreach ($dates as $i => $a) {
            foreach ($dates->slice($i + 1) as $b) {
                foreach ([[$a, $b], [$b, $a]] as [$start, $activity]) {
                    $startPath = $paths[$start['id']] ?? null;
                    $activityPath = $paths[$activity['id']] ?? null;
                    if ($startPath === null || $activityPath === null) {
                        continue;
                    }

                    $follows = 0;
                    $pairs = 0;
                    $months = [];
                    foreach ($rows as $row) {
                        $s = InMemoryRowFilter::timestamp(data_get($row, $startPath));
                        $t = InMemoryRowFilter::timestamp(data_get($row, $activityPath));
                        if ($s === null || $t === null) {
                            continue;
                        }
                        $pairs++;
                        if ($t >= $s) {
                            $follows++;
                        }
                        $months[date('Y-m', $s)] = ($months[date('Y-m', $s)] ?? 0) + 1;
                    }

                    if ($pairs < self::COHORT_MIN_ROWS
                        || $follows / $pairs < self::COHORT_MIN_FOLLOWS
                        || count($months) < self::COHORT_MIN_INTAKES) {
                        continue;
                    }

                    ksort($months);

                    return [
                        'start' => $start,
                        'activity' => $activity,
                        'entity' => $this->entityField($object, $rows, $fields),
                        'cohorts' => count($months),
                        'sizes' => array_map('floatval', array_slice(array_values($months), 0, 8)),
                    ];
                }
            }
        }

        return null;
    }

    /**
     * The thing being retained — a customer, an account, a user.
     *
     * Retention counts the entities that came BACK, not the events they
     * generated: one customer returning twice is one customer. Without such a
     * field the table can still count rows, but it is counting activity, not
     * loyalty — so the field is looked for by what it is called AND by the one
     * property an identifier always has: it is nearly unique.
     *
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>|null
     */
    private function entityField(array $object, array $rows, array $fields): ?array
    {
        $named = collect($fields)->first(function (array $f) use ($object, $rows): bool {
            if (! in_array($f['type'] ?? '', ['string', 'number'], true)) {
                return false;
            }
            $name = Str::lower(Str::ascii((string) ($f['name'] ?? '').' '.(string) ($f['slug'] ?? '')));
            if (preg_match('/(customer|cliente|user|usuario|account|cuenta|member|socio|email|correo)/i', $name) !== 1) {
                return false;
            }

            // A "customer name" column with six distinct values is a segment, not
            // an identity. An entity is nearly unique by definition.
            $distinct = $this->distinctCount($object, $rows, $f);

            return count($rows) > 0 && $distinct >= count($rows) * self::COHORT_ENTITY_UNIQUENESS;
        });

        return is_array($named) ? $named : null;
    }

    /**
     * How many distinct values a dimension actually carries in the sample — the
     * number that decides whether a chart is readable or a hairball.
     *
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $field
     */
    private function distinctCount(array $object, array $rows, array $field): int
    {
        $path = FieldPaths::forObject($object)[$field['id']] ?? ($field['slug'] ?? null);
        if ($path === null) {
            return 0;
        }
        $seen = [];
        foreach ($rows as $row) {
            $v = data_get($row, $path);
            if (is_scalar($v) && trim((string) $v) !== '') {
                $seen[(string) $v] = true;
            }
        }

        return count($seen);
    }

    /**
     * The spread a mean hides: for the category with the longest tail, its median
     * and its 75th percentile. Null when no category carries enough records for
     * quartiles to mean anything — which is exactly the case for a pre-aggregated
     * source, where each category IS a single row and has no spread at all.
     *
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $dim
     * @param  array<string, mixed>  $measure
     * @return array{category: string, median: float, p75: float, medians: list<float>, skewed: bool}|null
     */
    private function spreadOf(array $object, array $rows, array $dim, array $measure): ?array
    {
        $paths = FieldPaths::forObject($object);
        $dimPath = $paths[$dim['id']] ?? ($dim['slug'] ?? null);
        $numPath = $paths[$measure['id']] ?? ($measure['slug'] ?? null);

        $byCategory = [];
        foreach ($rows as $row) {
            $key = data_get($row, $dimPath);
            $value = data_get($row, $numPath);
            if (is_scalar($key) && trim((string) $key) !== '' && is_numeric($value)) {
                $byCategory[(string) $key][] = (float) $value;
            }
        }

        $best = null;
        $medians = [];
        foreach ($byCategory as $category => $values) {
            if (count($values) < self::SPREAD_MIN_PER_CATEGORY) {
                continue;
            }
            sort($values);
            $median = $this->quantile($values, 0.5);
            $p75 = $this->quantile($values, 0.75);
            $medians[] = round($median, 2);
            // The widest tail relative to the middle is the one worth showing.
            $tail = $median > 0 ? ($p75 - $median) / $median : 0.0;
            if ($best === null || $tail > $best['tail']) {
                $best = [
                    'category' => $category,
                    'median' => round($median, 2),
                    'p75' => round($p75, 2),
                    'tail' => $tail,
                ];
            }
        }

        if ($best === null || $best['p75'] <= $best['median']) {
            return null; // no spread worth a claim
        }

        return [
            'category' => $best['category'],
            'median' => $best['median'],
            'p75' => $best['p75'],
            'medians' => array_slice($medians, 0, 6),
            'skewed' => $best['tail'] >= 0.5, // the top quarter is 50%+ above the middle
        ];
    }

    /** @param  list<float>  $sorted */
    private function quantile(array $sorted, float $q): float
    {
        $n = count($sorted);
        if ($n === 0) {
            return 0.0;
        }
        $pos = ($n - 1) * $q;
        $low = (int) floor($pos);
        $high = (int) ceil($pos);
        if ($low === $high) {
            return $sorted[$low];
        }

        return $sorted[$low] + ($pos - $low) * ($sorted[$high] - $sorted[$low]);
    }

    /**
     * Days between the first and last dated row — how much history there is to
     * read a season out of.
     *
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $date
     */
    private function spanDays(array $object, array $rows, array $date): int
    {
        $path = FieldPaths::forObject($object)[$date['id']] ?? ($date['slug'] ?? null);
        $stamps = [];
        foreach ($rows as $row) {
            $ts = InMemoryRowFilter::timestamp(data_get($row, $path));
            if ($ts !== null) {
                $stamps[] = $ts;
            }
        }
        if (count($stamps) < 2) {
            return 0;
        }

        return (int) round((max($stamps) - min($stamps)) / 86400);
    }

    /**
     * Volume (summed) and rate (averaged) per category, for the top categories by
     * volume — the two aligned series a dual-axis combo draws.
     *
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $dim
     * @param  array<string, mixed>  $volume
     * @param  array<string, mixed>  $rate
     * @return array{values: list<float>, line: list<float>}
     */
    private function volumeAgainstRate(array $object, array $rows, array $dim, array $volume, array $rate, int $cap): array
    {
        $paths = FieldPaths::forObject($object);
        $dimPath = $paths[$dim['id']] ?? ($dim['slug'] ?? null);
        $volPath = $paths[$volume['id']] ?? ($volume['slug'] ?? null);
        $ratePath = $paths[$rate['id']] ?? ($rate['slug'] ?? null);

        $sums = [];
        $rates = [];
        foreach ($rows as $row) {
            $key = data_get($row, $dimPath);
            if (! is_scalar($key) || trim((string) $key) === '') {
                continue;
            }
            $key = (string) $key;
            $v = data_get($row, $volPath);
            $r = data_get($row, $ratePath);
            if (is_numeric($v)) {
                $sums[$key] = ($sums[$key] ?? 0) + (float) $v;
            }
            if (is_numeric($r)) {
                $rates[$key][] = (float) $r;
            }
        }
        arsort($sums);
        $sums = array_slice($sums, 0, $cap, true);

        $values = [];
        $line = [];
        foreach ($sums as $key => $sum) {
            // Only categories the rate actually covers — a combo with a hole in
            // the line is worse than no combo.
            if (! isset($rates[$key])) {
                continue;
            }
            $values[] = round((float) $sum, 2);
            $line[] = round(array_sum($rates[$key]) / count($rates[$key]), 2);
        }

        return ['values' => $values, 'line' => $line];
    }

    /**
     * The (x, y) pairs behind a correlation, for the card's mini-scatter — the
     * same points the real chart will draw, capped so the preview stays light.
     *
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{0: float, 1: float}>
     */
    private function scatterPoints(array $object, array $rows, string $xFieldId, string $yFieldId, int $cap = 60): array
    {
        $paths = FieldPaths::forObject($object);
        $xPath = $paths[$xFieldId] ?? null;
        $yPath = $paths[$yFieldId] ?? null;
        if ($xPath === null || $yPath === null) {
            return [];
        }

        $points = [];
        foreach ($rows as $row) {
            $x = data_get($row, $xPath);
            $y = data_get($row, $yPath);
            if (is_numeric($x) && is_numeric($y)) {
                $points[] = [round((float) $x, 2), round((float) $y, 2)];
            }
            if (count($points) >= $cap) {
                break;
            }
        }

        return $points;
    }

    /**
     * Weeks until a declining weekly series reaches 0 at its recent average
     * pace — the run-rate ETA. Null when it isn't clearly declining, the ETA is
     * implausible (≤1 or >52), or the series is too short.
     *
     * @param  list<float>  $series
     */
    private function weeksToZero(array $series): ?int
    {
        $n = count($series);
        if ($n < 4) {
            return null;
        }
        $last = $series[$n - 1];
        if ($last <= 0) {
            return null;
        }
        // Average step over the recent half — steadier than first-to-last.
        $recent = array_slice($series, (int) floor($n / 2));
        $step = (end($recent) - $recent[0]) / max(1, count($recent) - 1);
        if ($step >= 0) {
            return null; // flat or rising — no ETA to zero
        }
        $weeks = (int) ceil($last / abs($step));

        return ($weeks >= 2 && $weeks <= 52) ? $weeks : null;
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $field
     * @return list<float>
     */
    private function numericValuesOf(array $object, array $rows, array $field): array
    {
        $paths = FieldPaths::forObject($object);
        $path = $paths[$field['id']] ?? ($field['slug'] ?? null);

        return array_values(array_map('floatval', array_filter(
            array_map(fn (array $r) => data_get($r, $path), $rows),
            'is_numeric',
        )));
    }

    /**
     * A finding's identity — the exact analysis, down to the object and the
     * aggregation (unlike the semantic key, which is about what it SHOWS).
     *
     * @param  array<string, mixed>  $chart
     */
    private function identityOf(string $objectId, array $chart): string
    {
        if (($chart['__gauge'] ?? false) === true) {
            return 'gauge|'.$objectId.'|'.($chart['field_id'] ?? '');
        }

        return json_encode([
            $objectId,
            $chart['group_by_field_id'] ?? null,
            $chart['x_field_id'] ?? null,
            $chart['y_field_id'] ?? null,
            $chart['aggregation'] ?? 'count',
        ]);
    }
}
