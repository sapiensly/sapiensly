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
        $head = fn (string ...$names): int => collect($names)->contains(
            fn (string $n): bool => $this->domain->isHeadline($domain, $n)
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
            && $this->semantics->measureTypeOf($f) === SemanticProfile::MEASURE_ADDITIVE);
        $rate = collect($fields)->first(fn (array $f): bool => in_array($f['type'] ?? '', ['number', 'currency'], true)
            && $this->semantics->measureTypeOf($f) === SemanticProfile::MEASURE_RATIO);
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

        // 4) GAUGE — a ratio measure (e.g. FCR %) read against a target.
        foreach ($fields as $f) {
            if (! in_array($f['type'] ?? '', ['number', 'currency'], true)) {
                continue;
            }
            if ($this->semantics->measureTypeOf($f) !== SemanticProfile::MEASURE_RATIO) {
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
            $target = $value <= 100 ? 80 : round($value * 1.15);
            $out[] = [
                'kind' => 'gauge',
                'kicker' => ($es ? 'Medidor · ' : 'Gauge · ').Str::upper(Str::limit($nameOf($f), 12, '')),
                'title' => $nameOf($f).($es ? ' contra la meta' : ' vs. target'),
                'why' => $es
                    ? "{$nameOf($f)} está en {$value}%, a ".round(abs($target - $value), 1).' pts de la meta de '.$target.'%. Un medidor lo deja al frente.'
                    : "{$nameOf($f)} is at {$value}%, ".round(abs($target - $value), 1).' pts from the '.$target.'% target. A gauge puts it up front.',
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
                'flag' => $value < $target ? ['tone' => 'hot', 'text' => round(abs($target - $value), 1).' pts '.($es ? 'a la meta' : 'to target')] : null,
            ];
            break; // one gauge is enough per object
        }

        // 5) BREAKDOWN — a dimension worth splitting the measure by when nothing
        // concentrated (evenly spread reads as a ranking, not a pareto).
        if (! isset($facts['concentracion']) && isset($facts['top_values'])) {
            $topName = (string) array_key_first($facts['top_values']);
            $tv = $facts['top_values'][$topName];
            $dim = $byName(Str::lower(Str::ascii($topName)));
            $measure = collect($fields)->first(fn (array $f): bool => in_array($f['type'] ?? '', ['number', 'currency'], true)
                && $this->semantics->measureTypeOf($f) === SemanticProfile::MEASURE_ADDITIVE);
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
            foreach ($object['fields'] ?? [] as $f) {
                if (! is_array($f) || ! in_array($f['type'] ?? '', ['number', 'currency'], true)) {
                    continue;
                }
                if ($this->semantics->measureTypeOf($f) === SemanticProfile::MEASURE_RATIO
                    && $this->domain->isHeadline($domain, (string) ($f['name'] ?? ''))
                    && count($gaps) < 3) {
                    $gaps[(string) ($f['name'] ?? '')] = ['text' => Str::limit((string) ($f['name'] ?? ''), 18, '').($es ? ' sin medidor' : ' has no gauge')];
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
