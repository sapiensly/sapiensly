<?php

namespace App\Services\Builder;

use App\Facades\TenantCache;
use App\Models\App;
use App\Models\User;
use App\Services\Connected\ConnectedIntegrationResolver;
use App\Services\Connected\ConnectedObjectReader;
use App\Services\Express\ComputedFactsBuilder;
use App\Services\Express\SemanticProfile;
use App\Services\Records\InMemoryRowFilter;
use Illuminate\Support\Str;

/**
 * The intelligence behind "Agregar gráfica": instead of waiting for the user to
 * name a chart, it reads the board's real data and PROPOSES the analyses worth
 * adding — each grounded in a computed fact ("80% del backlog en 4 motivos"),
 * deduped against what the tablero already shows, ranked by how loud the data
 * is and how central the measure is to the business.
 *
 * Deterministic and side-effect-free (bar a short-TTL row cache). It composes
 * the existing analytics primitives: {@see ComputedFactsBuilder} for the real
 * numbers, {@see SemanticProfile} for legal aggregations, {@see DomainClassifier}
 * for the business lens. An AI layer may later re-rank + reword on top — this is
 * the honest floor it refines, never replaces.
 */
class ChartRecommender
{
    /** How many rows to sample per object for the facts. */
    private const SAMPLE = 500;

    /** Row sample cache TTL (seconds) — the panel opens deliberately. */
    private const ROWS_TTL = 120;

    /** Cached recommendation spec TTL (seconds) — long enough for a build session. */
    private const SPEC_TTL = 1800;

    private const MAX_RECS = 5;

    public function __construct(
        private ConnectedObjectReader $reader,
        private ConnectedIntegrationResolver $integrations,
        private ComputedFactsBuilder $facts,
        private SemanticProfile $semantics,
        private DomainClassifier $domain,
        private RecommendationNarrator $narrator,
        private DataQualityCheck $quality,
        private CrossSourceAnalyzer $crossSource,
        private DerivedMetricProposer $derived,
    ) {}

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $page
     * @return array{domain: array{sector: string, label: string}, sources: int, total_rows: int, recommendations: list<array<string, mixed>>, gaps: list<array{text: string}>}
     */
    public function recommend(App $app, array $manifest, array $page, ?User $actor, string $lang = 'es'): array
    {
        $es = $lang !== 'en';
        $connected = array_values(array_filter(
            $manifest['objects'] ?? [],
            fn ($o): bool => is_array($o) && (($o['source']['type'] ?? null) === 'connected'),
        ));
        $domain = $this->domain->classify($connected, $lang);
        $names = $this->fieldNames($manifest);
        // A generic dimension field ("Key") named the same across sources hides
        // WHICH dimension it is — the object's distinguishing suffix does
        // ("Tickets By Dimension · Reason" → reason). Fold it in so those cuts
        // dedupe by what they actually break down by.
        $hints = $this->objectHints($manifest);
        // Dedup is SEMANTIC, not by exact ids: the board carries the same cut
        // under several overlapping sources (reason/cause/key breakdowns of
        // tickets), so «Total Tickets by Reason» must be recognised as already
        // shown whichever object backs it, and regardless of sum-vs-count.
        $existing = $this->existingSemanticKeys($page['blocks'] ?? [], $names, $hints);

        $candidates = [];
        $totalRows = 0;
        $factsByObject = [];
        foreach ($connected as $object) {
            try {
                $rows = $this->sampleRows($app, $object, $actor);
                if ($rows === []) {
                    continue;
                }
                $totalRows += count($rows);
                $facts = $this->facts->build($object, $rows);
                $factsByObject[$object['id']] = ['object' => $object, 'rows' => $rows, 'facts' => $facts];

                foreach ($this->candidatesFor($object, $rows, $facts, $domain, $es) as $c) {
                    $semKey = $this->semanticKeyOf($object['id'], $c['chart'], $names, $hints);
                    if (isset($existing[$semKey])) {
                        continue; // the tablero already shows this analysis
                    }
                    $c['identity'] = $this->identityOf($object['id'], $c['chart']);
                    // Key candidates by the SEMANTIC cut too, so two overlapping
                    // sources don't both propose "the same chart".
                    $candidates[$semKey] = $this->rankBest($candidates[$semKey] ?? null, $c);
                }
            } catch (\Throwable) {
                continue; // one malformed object never sinks the whole panel
            }
        }

        // Cross-source findings: the join no single chart shows (volume in one
        // source vs performance in another over a shared dimension).
        foreach ($this->crossSource->analyze($factsByObject, $names, $hints, $es) as $f) {
            $f['identity'] = 'cross|'.($f['dim'] ?? '');
            $f['score'] = $f['base'] + ($f['flag'] !== null ? 6 : 0);
            $candidates['cross|'.($f['dim'] ?? '')] = $f;
        }

        // Derived metrics: ratios the board doesn't carry (reopen rate…).
        foreach ($this->derived->analyze($factsByObject, $es) as $f) {
            $key = 'derived|'.($f['metric'] ?? '');
            $f['identity'] = $key;
            $f['score'] = $f['base'];
            $candidates[$key] = $f;
        }

        $ranked = collect($candidates)
            ->sortByDesc('score')
            ->take(self::MAX_RECS)
            ->values();

        $recs = $ranked->map(fn (array $c) => $this->present($app, $page, $c))->all();
        // AI refines: reorders + rewords in the business voice, keeping the
        // numbers. Off by default (config-gated); passes through untouched.
        $recs = $this->narrator->narrate($recs, ['sector' => $domain['sector'], 'label' => $domain['label']], $actor, $lang, $app->id);

        return [
            'domain' => ['sector' => $domain['sector'], 'label' => $domain['label']],
            'sources' => count($factsByObject),
            'total_rows' => $totalRows,
            'recommendations' => $recs,
            'gaps' => $this->gaps($factsByObject, $existing, $domain, $es),
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
     * dimensions it can be broken down by, in business terms — for the "fuentes
     * leídas" panel.
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

    /**
     * The cached chart spec for a recommendation id, or null if it expired.
     *
     * @return array{chart: array<string, mixed>, object_id: string}|null
     */
    public function specFor(App $app, string $recId): ?array
    {
        $spec = TenantCache::get($this->specKey($app, $recId));

        return is_array($spec) ? $spec : null;
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

        // 3) GAUGE — a ratio measure (e.g. FCR %) read against a target.
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

        // 4) BREAKDOWN — a dimension worth splitting the measure by when nothing
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

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function present(App $app, array $page, array $candidate): array
    {
        $recId = substr(sha1($app->id.'|'.($page['id'] ?? '').'|'.$candidate['identity']), 0, 16);

        // Findings carrying an `insight` (cross-source joins, derived
        // metrics) are added as INSIGHT blocks — the value isn't a single viz.
        if (isset($candidate['insight'])) {
            TenantCache::put($this->specKey($app, $recId), [
                'kind' => 'insight',
                'insight' => $candidate['insight'],
            ], self::SPEC_TTL);

            return [
                'id' => $recId,
                'kicker' => $candidate['kicker'],
                'title' => $candidate['title'],
                'why' => $candidate['why'],
                'form' => 'insight',
                'flag' => $candidate['flag'],
                'preview' => $candidate['preview'],
            ];
        }

        $isGauge = ($candidate['chart']['__gauge'] ?? false) === true;
        $chart = $candidate['chart'];
        unset($chart['__gauge']);
        // The spec + kind + object are cached so «Agregar» inserts exactly what
        // was proposed, without trusting a client round-trip.
        TenantCache::put($this->specKey($app, $recId), [
            'kind' => $isGauge ? 'gauge' : 'chart',
            'chart' => $chart,
            'object_id' => $this->objectIdFor($candidate),
        ], self::SPEC_TTL);

        return [
            'id' => $recId,
            'kicker' => $candidate['kicker'],
            'title' => $candidate['title'],
            'why' => $candidate['why'],
            'form' => $isGauge ? 'gauge' : ($chart['chart_type'] ?? 'bar'),
            'flag' => $candidate['flag'],
            'preview' => $candidate['preview'],
        ];
    }

    // -- gap analysis --------------------------------------------------------

    /**
     * What the board ISN'T showing yet — surfaced as chips. Deterministic:
     * ratio measures with no gauge, a period-comparison the KPIs never make,
     * a leading dimension never broken down.
     *
     * @param  array<string, array{object: array<string,mixed>, rows: list<array<string,mixed>>, facts: array<string,mixed>}>  $byObject
     * @param  array<string, true>  $existing
     * @param  array{sector: string, label: string, headline: list<string>}  $domain
     * @return list<array{text: string}>
     */
    private function gaps(array $byObject, array $existing, array $domain, bool $es): array
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
     * A cached row sample for a connected object; empty on any read failure
     * (recommendations degrade quietly, never surface a transport error).
     *
     * @param  array<string, mixed>  $object
     * @return list<array<string, mixed>>
     */
    private function sampleRows(App $app, array $object, ?User $actor): array
    {
        $integration = $this->integrations->resolve($app, $object['source']['integration_id'] ?? null);
        if ($integration === null) {
            return [];
        }
        $key = 'chartrec:rows:'.sha1($app->id.'|'.($object['id'] ?? '').'|'.($actor?->id ?? 'x'));

        try {
            return TenantCache::remember($key, self::ROWS_TTL, function () use ($object, $integration, $actor): array {
                $result = $this->reader->list($object, $integration, ['limit' => self::SAMPLE], $actor);

                return ($result['ok'] ?? false) ? array_values($result['rows'] ?? []) : [];
            });
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $dim
     * @param  array<string, mixed>|null  $measure
     * @return list<float>
     */
    private function breakdownValues(array $object, array $rows, array $dim, ?array $measure, int $cap): array
    {
        $paths = $this->pathIndex($object);
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
        $paths = $this->pathIndex($object);
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

    /** @return list<float> */
    private function numericValuesOf(array $object, array $rows, array $field): array
    {
        $paths = $this->pathIndex($object);
        $path = $paths[$field['id']] ?? ($field['slug'] ?? null);

        return array_values(array_map('floatval', array_filter(
            array_map(fn (array $r) => data_get($r, $path), $rows),
            'is_numeric',
        )));
    }

    /** @return array<string, string> field_id → external_path */
    private function pathIndex(array $object): array
    {
        return collect($object['source']['field_map'] ?? [])->pluck('external_path', 'field_id')->all();
    }

    // -- identity + cache keys -----------------------------------------------

    /**
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

    /**
     * field_id → normalised field NAME across every object — the vocabulary the
     * semantic dedupe compares in (so the same measure/dimension matches across
     * overlapping sources and id renames).
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, string>
     */
    private function fieldNames(array $manifest): array
    {
        $out = [];
        foreach ($manifest['objects'] ?? [] as $object) {
            foreach ($object['fields'] ?? [] as $f) {
                if (is_array($f) && isset($f['id'])) {
                    $out[$f['id']] = Str::lower(Str::ascii((string) ($f['name'] ?? $f['slug'] ?? '')));
                }
            }
        }

        return $out;
    }

    /**
     * objectId → the token that DISTINGUISHES the object among overlapping
     * sources: the name's "·" suffix ("Tickets By Dimension · Reason" → reason)
     * or the slug's last segment. Used to name a generic dimension field.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, string>
     */
    private function objectHints(array $manifest): array
    {
        $out = [];
        foreach ($manifest['objects'] ?? [] as $object) {
            $name = (string) ($object['name'] ?? '');
            $hint = str_contains($name, '·')
                ? Str::afterLast($name, '·')
                : Str::afterLast((string) ($object['slug'] ?? ''), '_');
            $out[(string) ($object['id'] ?? '')] = Str::lower(Str::ascii(trim($hint)));
        }

        return $out;
    }

    /** A dimension field whose name says nothing about WHICH dimension it is. */
    private const GENERIC_DIM = '/^(key|clave|dimension|dimensi\w*|grupo|group|valor|value|name|nombre)$/';

    /**
     * A chart's SEMANTIC identity — «what it shows», not which object/agg backs
     * it: {family}|{measure name}|{dimension name}. Two charts with the same
     * key are the same analysis (e.g. Total Tickets by Reason as a pareto vs a
     * bar, sum vs count, from different sources). A generic dimension name
     * ("Key") is replaced by the object's distinguishing hint.
     *
     * @param  array<string, mixed>  $chart
     * @param  array<string, string>  $names
     * @param  array<string, string>  $hints
     */
    private function semanticKeyOf(string $objectId, array $chart, array $names, array $hints): string
    {
        if (($chart['__gauge'] ?? false) === true) {
            return 'gauge|'.($names[$chart['field_id'] ?? ''] ?? '').'|';
        }
        $family = in_array($chart['chart_type'] ?? '', ['area', 'line'], true)
            ? 'trend'
            : 'breakdown';
        $measure = $names[$chart['y_field_id'] ?? ''] ?? 'count';
        if ($family === 'trend') {
            return 'trend|'.$measure.'|time';
        }
        $dim = $names[$chart['group_by_field_id'] ?? ''] ?? '';
        if ($dim === '' || preg_match(self::GENERIC_DIM, $dim) === 1) {
            $dim = $hints[$objectId] ?? $dim;
        }

        return 'breakdown|'.$measure.'|'.$dim;
    }

    /**
     * The semantic keys of the charts/gauges already on the page — the dedupe
     * set, walked recursively into containers.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<string, string>  $names
     * @param  array<string, string>  $hints
     * @return array<string, true>
     */
    private function existingSemanticKeys(array $blocks, array $names, array $hints): array
    {
        $seen = [];
        $walk = function (array $blocks) use (&$walk, &$seen, $names, $hints): void {
            foreach ($blocks as $b) {
                if (! is_array($b)) {
                    continue;
                }
                if (($b['type'] ?? null) === 'chart') {
                    $seen[$this->semanticKeyOf((string) ($b['data_source']['object_id'] ?? ''), [
                        'chart_type' => $b['chart_type'] ?? null,
                        'group_by_field_id' => $b['group_by_field_id'] ?? null,
                        'x_field_id' => $b['x_field_id'] ?? null,
                        'y_field_id' => $b['y_field_id'] ?? null,
                    ], $names, $hints)] = true;
                }
                if (($b['type'] ?? null) === 'gauge') {
                    $seen[$this->semanticKeyOf('', ['__gauge' => true, 'field_id' => $b['field_id'] ?? ''], $names, $hints)] = true;
                }
                if (is_array($b['blocks'] ?? null)) {
                    $walk($b['blocks']);
                }
            }
        };
        $walk($blocks);

        return $seen;
    }

    private function objectIdFor(array $candidate): string
    {
        // identity always starts with the object id (json array or gauge|id|…).
        $id = $candidate['identity'];
        if (str_starts_with($id, 'gauge|')) {
            return explode('|', $id)[1] ?? '';
        }
        $decoded = json_decode($id, true);

        return is_array($decoded) ? (string) ($decoded[0] ?? '') : '';
    }

    private function specKey(App $app, string $recId): string
    {
        return 'chartrec:spec:'.$app->id.':'.$recId;
    }
}
