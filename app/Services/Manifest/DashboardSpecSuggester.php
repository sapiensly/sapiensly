<?php

namespace App\Services\Manifest;

use App\Services\Analyst\MaturationCheck;
use App\Services\Analyst\RatioIdentity;
use App\Services\Connected\ConnectedObjectReader;
use App\Services\Express\ComputedFactsBuilder;
use App\Services\Express\DomainLexicon;
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

    /** Insight cards render 3 per row — two rows is a band, not an essay. */
    private const MAX_INSIGHTS = 5;

    private const BREAKDOWN_LIMIT = 12;

    public function __construct(
        private readonly SemanticProfile $semantics = new SemanticProfile,
        private readonly ComputedFactsBuilder $factsBuilder = new ComputedFactsBuilder,
        private readonly FactNarrator $narrator = new FactNarrator,
        private readonly RatioIdentity $ratios = new RatioIdentity(new SemanticProfile),
        private readonly MaturationCheck $maturation = new MaturationCheck,
    ) {}

    /**
     * The current suggest() call's previous-window rows — threaded to the
     * insight narration so cards are born with period-over-period deltas.
     *
     * @var list<array<string, mixed>>
     */
    private array $previousRows = [];

    /** The raw ask — for signals topic words can't carry (target numbers, layout presets). */
    private ?string $prompt = null;

    /**
     * The intent form (pareto/embudo/…) is a BOARD-level budget of one: the
     * primary's flagship takes it; when the primary has no categorical chart,
     * the first mini that does inherits it — never every mini (observed: two
     * paretos on one page, the FCR one mislabeled as motivos).
     */
    private bool $intentFormSpent = false;

    private bool $inMulti = false;

    /**
     * Words the user put NEXT TO the target («meta de FCR de 80%» → fcr) —
     * the gauge must bind the measure they name, wherever it lives, not the
     * primary's first ratio.
     *
     * @var list<string>
     */
    private array $gaugeHintWords = [];

    private ?float $gaugeTarget = null;

    private bool $gaugeIsPct = false;

    /** With several objects, how much of the board the primary keeps. */
    private const PRIMARY_KPIS = 4;

    private const PRIMARY_CHARTS = 4;

    /** Asked-dimension guests each need their chart; the chart cap still rules the page. */
    private const MAX_SECONDARIES = 5;

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
    public function suggestMulti(array $objects, string $lang = 'es', array $rowsByObject = [], array $promptTopics = [], array $previousRowsByObject = [], ?string $prompt = null): array
    {
        $objects = array_values(array_filter($objects, 'is_array'));
        if ($objects === []) {
            return [];
        }

        $this->intentFormSpent = false;
        $this->gaugeHintWords = [];
        $this->gaugeTarget = null;
        $this->gaugeIsPct = false;
        $this->inMulti = true;

        try {
            return $this->suggestMultiInner($objects, $lang, $rowsByObject, $promptTopics, $previousRowsByObject, $prompt);
        } finally {
            $this->inMulti = false;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $objects
     * @param  array<string, list<array<string, mixed>>>  $rowsByObject
     * @param  list<string>  $promptTopics
     * @param  array<string, list<array<string, mixed>>>  $previousRowsByObject
     * @return array<string, mixed>
     */
    private function suggestMultiInner(array $objects, string $lang, array $rowsByObject, array $promptTopics, array $previousRowsByObject, ?string $prompt): array
    {
        $primary = $objects[0];
        $spec = $this->suggest($primary, $lang, $rowsByObject[$primary['id'] ?? ''] ?? [], $promptTopics, $previousRowsByObject[$primary['id'] ?? ''] ?? [], $prompt);
        $secondaries = array_values(array_filter(
            array_slice($objects, 1),
            fn (array $o): bool => ($rowsByObject[$o['id'] ?? ''] ?? []) !== [],
        ));
        if ($secondaries === []) {
            $spec['charts'] = $this->rebindGaugeToNamedMeasure($spec['charts'] ?? [], $objects);

            return $this->applyExecutivePreset($spec, $prompt);
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

        // The mini budget serves ASKED dimensions first: a secondary whose
        // name/slug/cut-argument matches a prompt topic (lexicon-bridged)
        // outranks incidental guests — «distribución por prioridad» must not
        // lose its only chart to an object nobody named.
        $topicVariants = DomainLexicon::expand(
            collect($promptTopics)->map(fn ($w): string => Str::lower(Str::ascii((string) $w))),
        );
        // Rank by WHERE in the ask the object's dimension was named:
        // «pareto de motivos, top causas, distribución por categoría» seats
        // reason objects before cause before category. Only DISTINGUISHING
        // text counts (cut argument, «· Cut» suffix, slug tail, FIELD
        // names/slugs) — the shared domain word anchors on everything.
        $promptAscii = Str::lower(Str::ascii((string) ($prompt ?? '')));
        $variantPos = [];
        foreach ($promptTopics as $topicWord) {
            $w = Str::lower(Str::ascii((string) $topicWord));
            $pos = $promptAscii === '' ? 0 : (int) (mb_strpos($promptAscii, $w) !== false ? mb_strpos($promptAscii, $w) : PHP_INT_MAX);
            foreach (DomainLexicon::expand(collect([$w])) as $variant) {
                $variantPos[$variant] = min($variantPos[$variant] ?? PHP_INT_MAX, $pos);
            }
        }
        $hayOf = function (array $o): string {
            $name = (string) ($o['name'] ?? '');
            $suffix = str_contains($name, '·') ? Str::afterLast($name, '·') : '';
            $slugTail = Str::afterLast((string) ($o['slug'] ?? ''), '_');
            $args = collect($o['source']['operations']['list']['arguments'] ?? [])
                ->filter(fn ($v): bool => is_scalar($v) && ! str_contains((string) $v, '{{'))
                ->implode(' ');
            // Only STRING fields: the dimension is what distinguishes an
            // object; numeric measures («Total Tickets») carry the shared
            // domain word onto everything.
            $fields = collect($o['fields'] ?? [])
                ->filter(fn ($f): bool => is_array($f) && in_array($f['type'] ?? '', ['string', 'single_select'], true))
                ->map(fn (array $f): string => (string) ($f['name'] ?? '').' '.(string) ($f['slug'] ?? ''))
                ->implode(' ');

            return Str::lower(Str::ascii($suffix.' '.$slugTail.' '.$args.' '.$fields));
        };
        // Field names smuggle the shared domain word back in («Total
        // Tickets» lives on every object) — a variant present in most hays
        // ranks nothing and is dropped, same ubiquity rule the fit uses.
        $allHays = array_map($hayOf, $secondaries);
        $n = max(1, count($allHays));
        foreach (array_keys($variantPos) as $variant) {
            $hits = count(array_filter($allHays, fn (string $h): bool => str_contains($h, (string) $variant)));
            if ($n >= 3 && $hits / $n > 0.7) {
                unset($variantPos[$variant]);
            }
        }
        $askedScore = function (array $o) use ($variantPos, $hayOf): int {
            $hay = $hayOf($o);
            $best = PHP_INT_MAX;
            foreach ($variantPos as $variant => $pos) {
                if (mb_strlen((string) $variant) >= 3 && str_contains($hay, (string) $variant)) {
                    $best = min($best, $pos);
                }
            }

            return $best;
        };
        // The DIMENSION an object answers, as the position of the word that
        // named it — three motivos objects share one dimension and must not
        // eat three chart seats while categoría goes unseated.
        $askedDimension = function (array $o) use ($variantPos, $hayOf): ?int {
            $hay = $hayOf($o);
            $best = null;
            foreach ($variantPos as $variant => $pos) {
                if ($pos !== PHP_INT_MAX && mb_strlen((string) $variant) >= 3 && str_contains($hay, (string) $variant)) {
                    $best = $best === null ? $pos : min($best, $pos);
                }
            }

            return $best;
        };
        $secondaries = collect($secondaries)
            ->sortBy($askedScore) // stable: ties keep the fit's order
            ->values()->all();

        // «filtro por categoría» names the SELECT's dimension explicitly —
        // adoption must honor it over whichever asked mini happens first.
        $filterHint = preg_match('/filtros? por ([a-záéíóúñ]+)/iu', (string) ($prompt ?? ''), $fm) === 1
            ? Str::lower(Str::ascii($fm[1]))
            : null;
        $filterCandidates = [];

        $datedInsights = [];
        $seated = 0;
        $chartedDimensions = [];
        // Two breakdowns over the SAME category set — identical slices, only the
        // measure differs (e.g. "total tickets by category" AND "closed count by
        // category", both cobranza/fulfillment/garantías/…) — must not draw the
        // same ring twice. Track the form(s) already used per category signature
        // so a colliding breakdown is re-formed (a donut becomes a bar), keeping
        // both measures but distinguishing them at a glance. Seed with the
        // primary's own breakdowns so a guest can't echo the primary's ring.
        $breakdownForms = [];
        $primaryStats = $this->semantics->columnStats($primary, $rowsByObject[$primary['id'] ?? ''] ?? []);
        foreach ($spec['charts'] as $primaryChart) {
            if (($primaryChart['group_by_field_id'] ?? null) === null || isset($primaryChart['x_field_id'])) {
                continue;
            }
            $sig = $this->categorySignature($primaryStats[$primaryChart['group_by_field_id']]['values'] ?? []);
            if ($sig !== null) {
                $breakdownForms[$sig][] = (string) ($primaryChart['chart_type'] ?? '');
            }
        }
        foreach ($secondaries as $secondary) {
            if ($seated >= self::MAX_SECONDARIES || count($spec['charts']) >= self::MAX_CHARTS) {
                break;
            }
            $slug = $secondary['slug'] ?? null;
            if ($slug === null) {
                continue;
            }
            // One breakdown per NAMED dimension: a second motivos object may
            // still lend a KPI or its filter, but not another chart seat.
            $dimension = $askedDimension($secondary);
            $dimensionCharted = $dimension !== null && in_array($dimension, $chartedDimensions, true);
            $mini = $this->suggest($secondary, $lang, $rowsByObject[$secondary['id'] ?? ''] ?? [], $promptTopics, $previousRowsByObject[$secondary['id'] ?? ''] ?? []);
            $name = (string) ($secondary['name'] ?? $slug);
            $miniSlugs = $this->fieldSlugIndex($secondary);
            $secStats = $this->semantics->columnStats($secondary, $rowsByObject[$secondary['id'] ?? ''] ?? []);

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

            if ($dimensionCharted) {
                $breakdown = null;
            }
            $added = false;
            foreach ([$trend, $breakdown] as $chart) {
                if ($chart === null || count($spec['charts']) >= self::MAX_CHARTS) {
                    continue;
                }
                $chart['object_slug'] = $slug;
                $chart['label'] = $this->labelWithObject((string) ($chart['label'] ?? ''), $name);
                // Re-form a breakdown that would repeat a ring already drawn for
                // the same category set (before the generic variety pass, so its
                // choice starts from a form that isn't a same-signature dup).
                $sig = ($chart['group_by_field_id'] ?? null) !== null && ! isset($chart['x_field_id'])
                    ? $this->categorySignature($secStats[$chart['group_by_field_id']]['values'] ?? [])
                    : null;
                if ($sig !== null && in_array((string) ($chart['chart_type'] ?? ''), $breakdownForms[$sig] ?? [], true)) {
                    $chart['chart_type'] = $this->pickBreakdownForm(
                        $breakdownForms[$sig],
                        $secStats[$chart['group_by_field_id']]['distinct'] ?? null,
                    );
                }
                $seatedChart = $this->varyForm($chart, $spec['charts']);
                $spec['charts'][] = $seatedChart;
                if ($sig !== null) {
                    $breakdownForms[$sig][] = (string) ($seatedChart['chart_type'] ?? '');
                }
                $added = true;
            }
            if ($added) {
                $seated++;
                if ($dimension !== null && $breakdown !== null) {
                    $chartedDimensions[] = $dimension;
                }
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

            // «filtro por categoría» when the primary can't offer one (reason
            // has 15 values; category lives on a cut): adopt the first
            // ASKED-dimension mini's filter, tagged with its owner so the
            // compiler binds the param to the right object.
            if (is_array($mini['category_filter'] ?? null) && $askedScore($secondary) !== PHP_INT_MAX) {
                $filterCandidates[] = ['cf' => $mini['category_filter'], 'slug' => $slug, 'name' => $name, 'secondary' => $secondary];
            }

            // The analytics band narrates from wherever the FACTS live: a
            // dateless primary (a breakdown) has no PoP/anomaly/slope to
            // tell — the dated guest's cards carry them (their compute
            // queries already point at their own object).
            if (($mini['date_field_id'] ?? null) !== null) {
                $datedInsights = array_merge($datedInsights, array_values(array_filter($mini['insights'] ?? [], 'is_array')));
            }
        }

        if (! isset($spec['category_filter']) && $filterCandidates !== []) {
            $dimOf = fn (array $c): string => Str::lower(Str::ascii(str_contains($c['name'], '·')
                ? trim(Str::afterLast($c['name'], '·'))
                : (string) collect($c['secondary']['source']['operations']['list']['arguments'] ?? [])
                    ->filter(fn ($v): bool => is_scalar($v) && ! str_contains((string) $v, '{{') && preg_match('/^\d/', (string) $v) !== 1)
                    ->first()));
            $chosen = null;
            if ($filterHint !== null) {
                $hintVariants = DomainLexicon::expand(collect([$filterHint]));
                $chosen = collect($filterCandidates)->first(
                    fn (array $c): bool => $hintVariants->contains(
                        fn (string $v): bool => $dimOf($c) !== '' && (str_contains($dimOf($c), $v) || str_contains($v, $dimOf($c))),
                    ),
                );
            }
            $chosen ??= $filterCandidates[0];
            $adopted = $chosen['cf'] + ['object_slug' => $chosen['slug']];
            // «Key» labels the column, not the dimension — the cut name, the
            // dimension argument, or the user's own filter word says what
            // they filter BY.
            if (in_array(Str::lower((string) ($adopted['label'] ?? '')), ['key', 'name', 'label', 'value'], true)) {
                $dim = $dimOf($chosen);
                if ($dim === '' && $filterHint !== null) {
                    $dim = $filterHint;
                }
                if ($dim !== '') {
                    $adopted['label'] = Str::headline($dim);
                }
            }
            $spec['category_filter'] = $adopted;
        }

        $cardKey = fn (array $c): string => Str::lower((string) ($c['variant'] ?? '').'|'.(string) ($c['title'] ?? ''));
        $byKey = [];
        foreach (array_values(array_filter($spec['insights'] ?? [], 'is_array')) as $i => $card) {
            $byKey[$cardKey($card)] = $i;
        }
        foreach ($datedInsights as $card) {
            $key = $cardKey($card);
            if (isset($byKey[$key])) {
                // Twin titles: the DATED narration wins — «Volumen del
                // periodo» with a trend beats the same count without one.
                $spec['insights'][$byKey[$key]] = $card;

                continue;
            }
            if (count($spec['insights'] ?? []) >= self::MAX_INSIGHTS) {
                break;
            }
            $byKey[$key] = count($spec['insights'] ?? []);
            $spec['insights'][] = $card;
        }

        // The primary pieces the guests displaced come back while there's room.
        foreach ($displaced as $chart) {
            if (count($spec['charts']) >= self::MAX_CHARTS) {
                break;
            }
            $spec['charts'][] = $this->varyForm($chart, $spec['charts']);
        }

        $spec['charts'] = $this->ensureGaugeAcrossBoard($spec['charts'] ?? [], $objects, $lang);
        $spec['charts'] = $this->rebindGaugeToNamedMeasure($spec['charts'] ?? [], $objects);

        return $this->applyExecutivePreset($spec, $prompt);
    }

    /**
     * «meta de FCR de 80%» must yield a gauge WHOEVER ended up primary: the
     * primary-local attempt fails when the primary has no ratio (prod: the
     * fit led with a ratio-less object), so the board gets a second chance
     * with every object's measures once they are all known — content
     * decides, not position.
     *
     * @param  list<array<string, mixed>>  $charts
     * @param  list<array<string, mixed>>  $objects
     * @return list<array<string, mixed>>
     */
    private function ensureGaugeAcrossBoard(array $charts, array $objects, string $lang): array
    {
        if ($this->gaugeTarget === null
            || collect($charts)->contains(fn (array $c): bool => ($c['chart_type'] ?? null) === 'gauge')) {
            return $charts;
        }
        foreach ($objects as $object) {
            $match = collect($object['fields'] ?? [])->first(fn ($f): bool => is_array($f)
                && ($f['type'] ?? '') === 'number'
                && $this->fieldMatchesGaugeHint($f)
                && (! $this->gaugeIsPct
                    || preg_match('/pct|percent|rate|ratio|share|porcentaje/i', (string) ($f['slug'] ?? '').' '.(string) ($f['name'] ?? '')) === 1));
            if ($match === null) {
                continue;
            }
            array_unshift($charts, array_filter([
                'label' => ($lang !== 'en' ? 'Meta: ' : 'Target: ').($match['name'] ?? $match['slug']),
                'chart_type' => 'gauge',
                'aggregation' => $this->gaugeIsPct ? 'avg' : 'sum',
                'y_field_id' => $match['id'],
                'object_slug' => $object['slug'] ?? null,
                'max_value' => $this->gaugeTarget,
                'format' => $this->gaugeIsPct ? 'percentage' : null,
            ], fn ($v) => $v !== null));

            return $charts;
        }

        return $charts;
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
     * A stable fingerprint of the category values a breakdown slices by, so two
     * breakdowns over the SAME categories can be recognised regardless of which
     * object or dimension argument produced them. Null when there are fewer than
     * two distinct values (not a real breakdown).
     *
     * @param  list<mixed>  $values  the group-by column's distinct values
     */
    private function categorySignature(array $values): ?string
    {
        $norm = collect($values)
            ->map(fn ($v): string => is_scalar($v) ? Str::lower(Str::ascii(trim((string) $v))) : '')
            ->filter()
            ->unique()
            ->sort()
            ->values();

        return $norm->count() >= 2 ? $norm->implode('|') : null;
    }

    /**
     * Choose a breakdown form that isn't already used for this category
     * signature, honouring cardinality (a >8-slice ring is unreadable, so many
     * categories prefer horizontal/treemap forms).
     *
     * @param  list<string>  $taken  forms already drawn for this signature
     */
    private function pickBreakdownForm(array $taken, ?int $distinct): string
    {
        $order = $distinct !== null && $distinct > 8
            ? ['hbar', 'treemap', 'bar']
            : ['bar', 'treemap', 'donut', 'hbar'];
        foreach ($order as $form) {
            if (! in_array($form, $taken, true)) {
                return $form;
            }
        }

        return $order[0];
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

    /**
     * Inline history behind the headline numbers: the first two KPIs of a
     * dated non-breakdown object get a sparkline over the date axis with the
     * KPI's own fold — the compact "how did we get here" beside the value.
     *
     * @param  list<array<string, mixed>>  $kpis
     * @return list<array<string, mixed>>
     */
    private function suggestSparks(array $kpis, string $grain, ?array $dateField): array
    {
        if ($dateField === null || $grain === SemanticProfile::GRAIN_DIMENSION) {
            return $kpis;
        }

        $decorated = 0;
        foreach ($kpis as $i => $kpi) {
            if ($decorated >= 2) {
                break;
            }
            $agg = (string) ($kpi['aggregation'] ?? 'count');
            if (! in_array($agg, ['count', 'sum', 'avg', 'min', 'max'], true) || isset($kpi['spark'])) {
                continue;
            }
            $kpis[$i]['spark'] = array_filter([
                'x_field_id' => $dateField['id'],
                'y_field_id' => $agg === 'count' ? null : ($kpi['field_id'] ?? null),
                'aggregation' => $agg,
            ], fn ($v) => $v !== null);
            $decorated++;
        }

        return $kpis;
    }

    /**
     * The dominant categorical as a SELECT filter: 2-12 sampled values on the
     * first usable category — the compiler wires every primary-object block
     * to params.<slug>, so picking «Envíos» re-scopes the whole board (an
     * unset param resolves empty and filters nothing).
     *
     * @param  list<array<string, mixed>>  $categoricals
     * @param  array<string, array{values: list<mixed>, distinct: int}>  $stats
     * @return array<string, mixed>|null
     */
    private function suggestCategoryFilter(array $categoricals, array $stats): ?array
    {
        foreach ($categoricals as $field) {
            $distinct = $stats[$field['id']]['distinct'] ?? null;
            if ($distinct === null || $distinct < 2 || $distinct > 12) {
                continue;
            }
            $options = collect($stats[$field['id']]['values'] ?? [])
                ->map(fn ($v): string => is_scalar($v) ? trim((string) $v) : '')
                ->filter()->unique()->take(12)->values();
            if ($options->count() < 2) {
                continue;
            }

            return [
                'field_id' => $field['id'],
                'label' => (string) ($field['name'] ?? $field['slug']),
                'options' => $options->all(),
            ];
        }

        return null;
    }

    /**
     * The flagship detail table: the rows behind the charts — category first,
     * then the leading measures, the date when there is one — sorted by the
     * biggest measure (or newest first on dated rows). Where a manager goes
     * from "the chart says X" to "which cases exactly".
     *
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $categoricals
     * @param  list<array<string, mixed>>  $numerics
     * @param  array<string, string>  $measureTypes
     * @return array<string, mixed>|null
     */
    private function suggestTable(array $object, string $grain, ?array $dateField, array $categoricals, array $numerics, array $measureTypes, bool $es): ?array
    {
        $measures = collect($numerics)
            ->filter(fn (array $f): bool => ($measureTypes[$f['id']] ?? '') !== SemanticProfile::MEASURE_IDENTIFIER)
            ->take(3)->values();
        $columns = collect([$categoricals[0]['id'] ?? null])
            ->concat($measures->pluck('id'))
            ->concat([$dateField['id'] ?? null])
            ->filter()->unique()->take(5)->values();
        if ($columns->count() < 2) {
            return null;
        }

        $lead = $measures->first(fn (array $f): bool => ($measureTypes[$f['id']] ?? '') === SemanticProfile::MEASURE_ADDITIVE)
            ?? $measures->first();
        $sort = $dateField !== null && $grain !== SemanticProfile::GRAIN_DIMENSION
            ? ['field_id' => $dateField['id'], 'direction' => 'desc']
            : ($lead !== null ? ['field_id' => $lead['id'], 'direction' => 'desc'] : null);

        return array_filter([
            'label' => $es ? 'Detalle' : 'Detail',
            'columns' => $columns->all(),
            'sort' => $sort !== null ? [$sort] : null,
            'limit' => 10,
        ], fn ($v) => $v !== null);
    }

    /**
     * "Meta de 80%": when the ask names a TARGET, the board leads with a
     * gauge of the matching measure against it. The number comes from the raw
     * prompt (topic words drop digits); no parseable number or no fitting
     * measure ⇒ no gauge — an invented target is a lie with a needle.
     *
     * @param  list<array<string, mixed>>  $charts
     * @param  list<array<string, mixed>>  $numerics
     * @param  array<string, string>  $measureTypes
     * @return list<array<string, mixed>>
     */
    private function withGaugeTarget(array $charts, array $numerics, array $measureTypes, bool $es): array
    {
        $prompt = (string) ($this->prompt ?? '');
        if ($prompt === '' || preg_match('/\b(meta|objetivo|target|goal)\b/iu', $prompt) !== 1) {
            return $charts;
        }
        if (preg_match('/\b(?:meta|objetivo|target|goal)\b([^0-9%]{0,40}?)(\d+(?:[.,]\d+)?)\s*(%)?/iu', $prompt, $m) !== 1) {
            return $charts;
        }
        $target = (float) str_replace(',', '.', $m[2]);
        $isPct = ($m[3] ?? '') === '%' || ($target <= 100 && str_contains($prompt, '%'));
        if ($target <= 0) {
            return $charts;
        }
        $this->gaugeTarget = $target;
        $this->gaugeIsPct = $isPct;

        // «meta de FCR de 80%» names WHICH measure the target is for — the
        // words between the meta-word and the number are the binding hint.
        $this->gaugeHintWords = collect(preg_split('/[^a-z0-9]+/i', Str::lower(Str::ascii((string) $m[1]))) ?: [])
            ->filter(fn ($w): bool => mb_strlen((string) $w) >= 3)
            ->values()->all();

        $candidates = collect($numerics)->filter(fn (array $f): bool => $isPct
            ? ($measureTypes[$f['id']] ?? '') === SemanticProfile::MEASURE_RATIO
            : ($measureTypes[$f['id']] ?? '') === SemanticProfile::MEASURE_ADDITIVE);
        $measure = $candidates->first(fn (array $f): bool => $this->fieldMatchesGaugeHint($f))
            ?? $candidates->first();
        if ($measure === null) {
            return $charts;
        }

        array_unshift($charts, array_filter([
            'label' => ($es ? 'Meta: ' : 'Target: ').($measure['name'] ?? $measure['slug']),
            'chart_type' => 'gauge',
            'aggregation' => $isPct ? 'avg' : 'sum',
            'y_field_id' => $measure['id'],
            'max_value' => $target,
            'format' => $isPct ? 'percentage' : null,
        ], fn ($v) => $v !== null));

        return $charts;
    }

    /** Does this field's name/slug share a word with the gauge's hint? */
    private function fieldMatchesGaugeHint(array $field): bool
    {
        if ($this->gaugeHintWords === []) {
            return false;
        }
        $hay = Str::lower(Str::ascii((string) ($field['name'] ?? '').' '.(string) ($field['slug'] ?? '')));

        return collect($this->gaugeHintWords)->contains(
            fn (string $w): bool => str_contains($hay, $w),
        );
    }

    /**
     * The measure «meta de FCR» names can live on a SECONDARY object (prod:
     * fcr_pct on Tickets Fcr while the primary only had pct_of_total). Once
     * every object is on the board, rebind the gauge to the best-named
     * measure — a %-looking numeric whose name carries the hint word.
     *
     * @param  list<array<string, mixed>>  $charts
     * @param  list<array<string, mixed>>  $objects
     * @return list<array<string, mixed>>
     */
    private function rebindGaugeToNamedMeasure(array $charts, array $objects): array
    {
        if ($this->gaugeHintWords === []) {
            return $charts;
        }
        foreach ($charts as $gi => $chart) {
            if (($chart['chart_type'] ?? null) !== 'gauge') {
                continue;
            }
            $current = collect($objects)->flatMap(fn (array $o) => $o['fields'] ?? [])
                ->firstWhere('id', $chart['y_field_id'] ?? null);
            if (is_array($current) && $this->fieldMatchesGaugeHint($current)) {
                return $charts; // already bound to the named measure
            }
            foreach ($objects as $object) {
                $match = collect($object['fields'] ?? [])->first(fn ($f): bool => is_array($f)
                    && ($f['type'] ?? '') === 'number'
                    && $this->fieldMatchesGaugeHint($f)
                    && (($chart['format'] ?? null) !== 'percentage'
                        || preg_match('/pct|percent|rate|ratio|share|porcentaje/i', (string) ($f['slug'] ?? '').' '.(string) ($f['name'] ?? '')) === 1));
                if ($match === null) {
                    continue;
                }
                $charts[$gi] = array_merge($chart, array_filter([
                    'y_field_id' => $match['id'],
                    'object_slug' => $object['slug'] ?? null,
                    'label' => preg_replace('/^(Meta|Target): .*/u', '$1: '.($match['name'] ?? $match['slug']), (string) ($chart['label'] ?? '')),
                ], fn ($v) => $v !== null));

                return $charts;
            }

            return $charts; // one gauge per board — nothing better found
        }

        return $charts;
    }

    /**
     * "Resumen ejecutivo": the ask names the ALTITUDE — keep the KPI band and
     * the insights whole, cap the charts at the four strongest. (The word is
     * a topic stopword, so only the raw prompt carries it.)
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function applyExecutivePreset(array $spec, ?string $prompt): array
    {
        if ($prompt !== null && preg_match('/ejecutiv|executive|overview/iu', $prompt) === 1) {
            $spec['charts'] = array_slice($spec['charts'] ?? [], 0, 4);
        }

        return $spec;
    }

    /**
     * The chart FORM the ask names in words — "top 15", "distribución",
     * "pareto/acumulado", "compara" — mapped deterministically so an explicit
     * form intent shapes the flagship breakdown without a model call (the
     * economy-mode complement: intent should shape form, and this vocabulary
     * is finite). Null when the ask names no form; the data-shape defaults
     * then rule as before.
     *
     * @param  list<string>  $topics
     */
    /**
     * One chart from a natural ask, over ALREADY-acquired objects — the
     * manual-adjust «agregar gráfica» chat. Deterministic mini-Express: the
     * intent vocabulary picks the form, the lexicon picks the object and the
     * measure/dimension the words name. Returns ok:false with a human reason
     * when the ask doesn't anchor (the caller answers the chat honestly).
     *
     * @param  list<array<string, mixed>>  $objects
     * @return array{ok: bool, chart?: array<string, mixed>, object?: array<string, mixed>, error?: string}
     */
    public function suggestChartFromAsk(array $objects, string $prompt, string $lang = 'es'): array
    {
        $stop = ['agrega', 'agregar', 'una', 'uno', 'grafica', 'gráfica', 'chart', 'nueva', 'nuevo', 'crea', 'crear', 'quiero', 'necesito', 'para', 'con', 'las', 'los', 'del', 'que', 'por', 'muestra', 'muestrame', 'dame'];
        $topics = collect(preg_split('/[^a-z0-9áéíóúñ%]+/iu', Str::lower(Str::ascii($prompt))) ?: [])
            ->filter(fn ($w): bool => mb_strlen((string) $w) >= 3 && ! in_array((string) $w, $stop, true))
            ->unique()->values();
        if ($topics->isEmpty()) {
            return ['ok' => false, 'error' => $lang !== 'en'
                ? 'Dime qué quieres graficar: la dimensión (motivos, categoría…) y opcionalmente la forma (pareto, top, distribución, tendencia).'
                : 'Tell me what to chart: the dimension and optionally the form (pareto, top, distribution, trend).'];
        }
        $variants = DomainLexicon::expand($topics);

        // The object whose DISTINGUISHING text the ask names (cut argument,
        // «· Cut» suffix, slug tail, string fields) — first match wins.
        $connected = array_values(array_filter($objects, fn ($o): bool => is_array($o)));
        $scored = collect($connected)->map(function (array $o) use ($variants): array {
            $name = (string) ($o['name'] ?? '');
            $suffix = str_contains($name, '·') ? Str::afterLast($name, '·') : '';
            $args = collect($o['source']['operations']['list']['arguments'] ?? [])
                ->filter(fn ($v): bool => is_scalar($v) && ! str_contains((string) $v, '{{'))->implode(' ');
            $fields = collect($o['fields'] ?? [])
                ->map(fn ($f): string => is_array($f) ? (string) ($f['name'] ?? '').' '.(string) ($f['slug'] ?? '') : '')->implode(' ');
            $hay = Str::lower(Str::ascii($suffix.' '.Str::afterLast((string) ($o['slug'] ?? ''), '_').' '.$args.' '.$fields));
            $hits = $variants->filter(fn (string $v): bool => mb_strlen($v) >= 3 && str_contains($hay, $v))->count();

            return ['object' => $o, 'hits' => $hits];
        })->sortByDesc('hits')->values();

        $best = $scored->first();
        if ($best === null || $best['hits'] === 0) {
            return ['ok' => false, 'error' => $lang !== 'en'
                ? 'No encontré esa dimensión en los datos de este tablero. Dimensiones disponibles: '.collect($connected)->pluck('name')->implode(', ').'.'
                : 'That dimension is not in this board\'s data. Available: '.collect($connected)->pluck('name')->implode(', ').'.'];
        }
        $object = $best['object'];

        $strings = array_values(array_filter($object['fields'] ?? [], fn ($f): bool => is_array($f) && in_array($f['type'] ?? '', ['string', 'single_select'], true)));
        $numerics = array_values(array_filter($object['fields'] ?? [], fn ($f): bool => is_array($f) && ($f['type'] ?? '') === 'number'));
        $dates = array_values(array_filter($object['fields'] ?? [], fn ($f): bool => is_array($f) && in_array($f['type'] ?? '', ['date', 'datetime'], true)));

        $named = function (array $fields) use ($variants): ?array {
            foreach ($fields as $f) {
                $hay = Str::lower(Str::ascii((string) ($f['name'] ?? '').' '.(string) ($f['slug'] ?? '')));
                if ($variants->contains(fn (string $v): bool => mb_strlen($v) >= 3 && str_contains($hay, $v))) {
                    return $f;
                }
            }

            return null;
        };

        $form = $this->intentForm($topics->all());
        $wantsTrend = $topics->contains(fn (string $w): bool => preg_match('/^(tendencia|evoluci|semanal|mensual|diari|trend)/u', $w) === 1);
        $measure = $named($numerics) ?? ($numerics[0] ?? null);
        $es = $lang !== 'en';

        if (($wantsTrend || $strings === []) && $dates !== []) {
            if ($measure === null) {
                return ['ok' => false, 'error' => $es ? 'Ese objeto no tiene una medida numérica para graficar.' : 'No numeric measure to chart there.'];
            }

            return ['ok' => true, 'object' => $object, 'chart' => [
                'label' => ($es ? 'Evolución de ' : 'Trend of ').Str::lower((string) $measure['name']),
                'chart_type' => 'line',
                'x_field_id' => $dates[0]['id'],
                'y_field_id' => $measure['id'],
                'aggregation' => 'sum',
                'bucket' => 'week',
                'description' => Str::ucfirst(($es ? 'Evolución semanal de ' : 'Weekly trend of ').Str::lower((string) $measure['name']).'.'),
            ]];
        }

        $dimension = $named($strings) ?? ($strings[0] ?? null);
        if ($dimension === null) {
            return ['ok' => false, 'error' => $es ? 'Ese objeto no tiene una dimensión categórica para desglosar.' : 'No categorical dimension to break down there.'];
        }
        $form ??= 'hbar';

        return ['ok' => true, 'object' => $object, 'chart' => array_filter([
            'label' => ($es ? 'Por ' : 'By ').Str::lower((string) $dimension['name']).' · '.(string) ($object['name'] ?? ''),
            'chart_type' => $form,
            'group_by_field_id' => $dimension['id'],
            'y_field_id' => $measure['id'] ?? null,
            'aggregation' => $measure !== null ? 'sum' : 'count',
            'description' => Str::ucfirst(Str::lower((string) ($measure['name'] ?? 'Registros')).' '.($es ? 'por' : 'by').' '.Str::lower((string) $dimension['name']).($form === 'pareto' ? ($es ? ', con % acumulado.' : ', with cumulative %.') : '.')),
        ], fn ($v) => $v !== null)];
    }

    private function intentForm(array $topics): ?string
    {
        $words = collect($topics)->map(fn ($w): string => Str::lower(Str::ascii((string) $w)));
        $has = fn (string $pattern): bool => $words->contains(
            fn (string $w): bool => preg_match($pattern, $w) === 1,
        );

        return match (true) {
            // concentr\w*: "dónde está concentrado el volumen" IS the pareto
            // ask — the interpreter phrased it as a participle and the form
            // escaped on a conjugation. "grueso (del problema)" is the same
            // intent in manager speech.
            $has('/^pareto$|^acumulad|^concentr|^grueso$/') => 'pareto',
            $has('/^embudo$|^funnel$/') => 'funnel',
            // "mapa de calor" tokenizes to two words; "heatmap" is one.
            $has('/^heatmap$/') || ($has('/^mapa$/') && $has('/^calor$/')) => 'heatmap',
            $has('/^top\d*$|^ranking$|^mayores$|^principales$/') => 'hbar',
            $has('/^distribuci|^proporci|^participaci|^reparto$|^share$/') => 'donut',
            $has('/^compar|^versus$|^vs$/') => 'bar',
            default => null,
        };
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
    public function suggest(array $object, string $lang = 'es', array $rows = [], array $promptTopics = [], array $previousRows = [], ?string $prompt = null): array
    {
        $this->previousRows = $previousRows;
        $this->prompt = $prompt;
        if (! $this->inMulti) {
            $this->intentFormSpent = false;
            $this->gaugeHintWords = [];
            $this->gaugeTarget = null;
            $this->gaugeIsPct = false;
        }
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
            'kpis' => $this->withoutImmaturePeriods($this->suggestSparks(
                $this->suggestKpis($object, $grain, $numerics, $measureTypes, $booleans, $es, $promptTopics, $stats, $dateField, $defaultRange),
                $grain,
                $dateField,
            ), $object, $rows, $dateField),
            'charts' => $this->withoutImmaturePeriods($this->ratesAtRowGrain($this->withGaugeTarget(
                $this->suggestCharts($grain, $dateField, $categoricals, $numerics, $measureTypes, $stats, $es, $object, $promptTopics),
                $numerics,
                $measureTypes,
                $es,
            ), $fields), $object, $rows, $dateField),
            'insights' => $this->suggestInsights($object, $categoricals, $booleans, $es, $rows),
            'category_filter' => $this->suggestCategoryFilter($categoricals, $stats),
            'table' => $this->suggestTable($object, $grain, $dateField, $categoricals, $numerics, $measureTypes, $es),
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * A derived rate may only be charted at the grain its rows are stored at.
     *
     * The KPI rule is not enough. `avg(otd_pct)` bucketed by WEEK averages seven
     * daily rates into one point — the same unweighted mean, at a coarser scale, and
     * a week with three quiet days lies exactly as hard as the KPI did. The reason
     * the per-row chart is honest at all is that a bucket holding ONE row makes the
     * average equal to that row's value; widen the bucket and the guarantee is gone.
     *
     * So the bucket is pinned to the row grain. That is exact for any per-row rate,
     * whatever the source's period is — a weekly source bucketed by day still puts
     * one row in each bucket.
     *
     * @param  list<array<string, mixed>>  $charts
     * @param  list<array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    private function ratesAtRowGrain(array $charts, array $fields): array
    {
        $derived = collect($fields)
            ->filter(fn (array $f): bool => is_array($f['derived_rate'] ?? null))
            ->keyBy('id');
        if ($derived->isEmpty()) {
            return $charts;
        }

        return collect($charts)->map(function (array $chart) use ($derived): array {
            if (($chart['aggregation'] ?? null) === 'avg'
                && isset($chart['bucket'])
                && $derived->has($chart['y_field_id'] ?? '')) {
                $chart['bucket'] = 'day';
            }

            return $chart;
        })->values()->all();
    }

    /**
     * The only honest KPI for a rate column — or none at all.
     *
     * `avg(otd_pct)` is not the rate. It is the unweighted mean of per-row rates,
     * which weights a day with 3 orders exactly like a day with 500. Two branches
     * of this class used to emit it (the namesake headline and the per-measure
     * loop), and the manifest validator rejects it — so THE FAST PATH WE TELL THE
     * MODEL TO USE GENERATED THE ONE BLOCK THE RULES FORBID. It retried five times,
     * consulted the framework reference mid-fight, and escaped the only way it
     * could: by deleting OTD from a board about on-time delivery.
     *
     * The server compiles what is mechanical. So the server has to know what the
     * data proved:
     *
     *   - numerator is a real column → SUM(numerator) ÷ SUM(denominator), which the
     *     platform recomputes on every load. That IS the rate.
     *   - numerator is a DIFFERENCE (on-time = delivered − late) → ratio_denominator
     *     points at ONE column, so no KPI can express it. Emit nothing. A missing
     *     headline is honest; a wrong one is not. The rate still gets its per-row
     *     chart, where each value is exactly right.
     *   - nothing proven → the old average, which for an ordinary score is fine.
     *
     * @param  array<string, mixed>  $field
     * @param  array<string, array{values: list<mixed>, distinct: int, null_rate: float, all_equal: bool}>  $stats
     * @return array<string, mixed>|null
     */
    private function rateKpi(array $field, array $stats): ?array
    {
        $name = (string) ($field['name'] ?? $field['slug'] ?? '');
        $derived = is_array($field['derived_rate'] ?? null) ? $field['derived_rate'] : null;

        if ($derived === null) {
            return [
                // Field name only — the subtitle names it as an average.
                'label' => $name,
                'aggregation' => 'avg',
                'field_id' => $field['id'],
                'icon' => 'star',
                ...$this->kpiDisplay($field, $stats),
            ];
        }

        if (isset($derived['minus_field_id'])) {
            return null;
        }

        return [
            'label' => $name,
            'aggregation' => 'sum',
            'field_id' => $derived['numerator_field_id'],
            'ratio_denominator' => [
                'aggregation' => 'sum',
                'field_id' => $derived['denominator_field_id'],
            ],
            'format' => 'percentage',
            'icon' => 'percent',
        ];
    }

    /**
     * Cut the periods that have not happened yet out of every block.
     *
     * A live source reports an order the instant it is placed but cannot mark it
     * delivered-on-time until the promised date arrives, so the tail of the series
     * reads as a collapse to zero. Charted, it tells the director the operation
     * died and sends him to shout at an innocent courier — which is precisely the
     * board that shipped.
     *
     * The cutoff is a LAG, never a date: `< {{days_ago(4)}}` keeps excluding the
     * unresolved window as it rolls forward, where a literal 2026-07-09 would rot
     * into a lie the morning after. It is ANDed with whatever filter the block
     * already carries, so the range picker still works.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $dateField
     * @return list<array<string, mixed>>
     */
    private function withoutImmaturePeriods(array $blocks, array $object, array $rows, ?array $dateField): array
    {
        if ($blocks === [] || $rows === [] || $dateField === null) {
            return $blocks;
        }

        $maturation = $this->maturation->detect(
            $object,
            $rows,
            $this->ratios->detect($object, $rows),
        );
        $lag = 0;
        foreach ($maturation as $m) {
            if (($m['conclusive'] ?? false) === true) {
                $lag = max($lag, (int) $m['lag_days']);
            }
        }
        if ($lag <= 0) {
            return $blocks;
        }

        $cut = ['op' => 'lt', 'field_id' => $dateField['id'], 'value_expression' => "{{days_ago({$lag})}}"];

        return collect($blocks)->map(function (array $block) use ($cut): array {
            $own = is_array($block['filter'] ?? null) ? $block['filter'] : null;
            $block['filter'] = $own === null
                ? $cut
                : ['op' => 'and', 'conditions' => [$own, $cut]];

            return $block;
        })->values()->all();
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
                // The namesake is the board's headline, so it is the WORST place to
                // average a derived rate — and the likeliest, because the object is
                // usually named after it ("Métricas OTD Diarias" → otd_pct). This is
                // the branch that put avg(otd_pct) in the hero.
                $kpi = $this->rateKpi($namesake, $stats);
                if ($kpi !== null) {
                    $kpis[] = $kpi;
                }
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
                $kpi = $this->rateKpi($field, $stats);
                if ($kpi !== null) {
                    $kpis[] = $kpi;
                }

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
        } elseif ($this->hasWindowArguments($object)) {
            // A DATELESS pre-aggregated source with a from/to window: no date
            // field to bracket a compare query with, so the chip compares LIVE
            // against the previous window — the runtime re-reads the tool one
            // span back (compare_window: previous). Only for aggregations the
            // in-memory folder can compute over the second read.
            $fieldsById = collect($numerics)->keyBy('id');
            $kpis = array_map(function (array $kpi) use ($fieldsById): array {
                if (! in_array($kpi['aggregation'] ?? 'count', ['count', 'sum', 'avg', 'min', 'max'], true)) {
                    return $kpi;
                }
                $kpi['compare_window'] ??= 'previous';
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
     * Does the object's list operation carry a start-of-window argument (the
     * authored rolling from/to)? Those are the sources whose previous window
     * the runtime can re-read for a live compare.
     *
     * @param  array<string, mixed>  $object
     */
    private function hasWindowArguments(array $object): bool
    {
        $arguments = $object['source']['operations']['list']['arguments'] ?? null;
        if (! is_array($arguments) || $arguments === []) {
            return false;
        }

        return collect(ConnectedObjectReader::DATE_FROM_ARG_KEYS)
            ->contains(fn (string $key): bool => array_key_exists($key, $arguments));
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

        // "Mapa de calor": the calendar heatmap counts RECORDS per day, so it
        // is only honest on record-level rows with a real date axis and no
        // recency cap (a capped sample's density is the sampling window).
        if (! $this->intentFormSpent
            && $this->intentForm($promptTopics) === 'heatmap'
            && $grain === SemanticProfile::GRAIN_RAW
            && $dateField !== null
            && ! $this->isCappedSample($object)
            && count($charts) < self::MAX_CHARTS) {
            $this->intentFormSpent = true;
            $charts[] = [
                'label' => $es ? 'Actividad por día' : 'Daily activity',
                'chart_type' => 'heatmap',
                'aggregation' => 'count',
                'x_field_id' => $dateField['id'],
            ];
        }

        // Concentration: breakdowns per categorical, form chosen by REAL
        // cardinality (donut needs few slices; many go horizontal, capped) —
        // unless the ASK names a form: an explicit "top/pareto/distribución/
        // compara" overrides the data-shape default on the FLAGSHIP breakdown
        // (the rest keep the variety rotation). Deterministic intent→form:
        // the same words always produce the same chart, no model involved.
        $breakdownTypes = ['donut', 'bar', 'treemap'];
        $intentForm = $this->intentFormSpent ? null : $this->intentForm($promptTopics);
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
            if ($intentForm !== null && $intentForm !== 'heatmap') {
                if ($intentForm === 'funnel') {
                    // Stages are the sampled category values in source order
                    // (breakdown tools already return them sorted by volume);
                    // outside 2-6 real stages a funnel stops being one and the
                    // data-shape default stands.
                    $values = collect($stats[$field['id']]['values'] ?? [])
                        ->map(fn ($v): string => is_scalar($v) ? trim((string) $v) : '')
                        ->filter()->unique()->take(6)->values();
                    if ($values->count() >= 2) {
                        $chart['chart_type'] = 'funnel';
                        $chart['stages'] = $values->all();
                        $chart['label'] = ($es ? 'Embudo por ' : 'Funnel by ').Str::lower((string) ($field['name'] ?? $field['slug']));
                        $intentForm = null; // flagship only
                        $this->intentFormSpent = true; // …and once per BOARD
                    }
                } else {
                    // A part-of-whole ask over many slices reads better as a
                    // treemap than a 12-slice donut; every other form scales.
                    $chart['chart_type'] = ($intentForm === 'donut' && $distinct !== null && $distinct > 8)
                        ? 'treemap'
                        : $intentForm;
                    $intentForm = null; // flagship only
                    $this->intentFormSpent = true; // …and once per BOARD
                }
            }
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

            if ($dateField !== null) {
                // A time axis exists but had too few buckets for a line — one
                // bar per period IS the right picture (few periods, few bars).
                // Always charted on the DATE axis: grouping by the bucket-label
                // column instead is exactly the shape the compiler refuses
                // (illegal_aggregation, "the time axis in costume") — shipped
                // once: a degenerate weekly series suggested group_by
                // period_label and the whole build failed to compile. The
                // label column only picks the bucket granularity and the title.
                $charts[] = [
                    'label' => $label.($bucketLabel !== null
                        ? ($es ? ' por periodo' : ' by period')
                        : ($es ? ' por día' : ' by day')),
                    'chart_type' => 'bar',
                    'aggregation' => $agg,
                    'y_field_id' => $field['id'],
                    'x_field_id' => $dateField['id'],
                    'bucket' => $bucketLabel !== null ? 'week' : 'day',
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
            $insights = $this->narrator->narrate($insights, $this->factsBuilder->build($object, $rows, $this->previousRows));
        }

        return $insights;
    }
}
