<?php

use App\Ai\Tools\Builder\PlanDashboardTool;
use App\Services\Express\ComputedFactsBuilder;
use App\Services\Manifest\AppScaffolder;
use App\Services\Manifest\DashboardSpecSuggester;
use App\Services\Manifest\ManifestValidator;
use App\Support\Branding\ColorPalette;
use Illuminate\Support\Str;

/**
 * The deterministic dashboard suggester (the Express path's author). These
 * pin two prod-observed polish fixes: humane KPI labels (no "Suma …"/"Promedio
 * …" prefix — the aggregation is named by the card subtitle instead) and never
 * charting a recency-capped source (mode:latest) as a count-over-time trend.
 */
function dss_comments_object(?string $mode): array
{
    return [
        'id' => 'obj_npscomments0',
        'slug' => 'nps_comments',
        'name' => 'Nps Comments',
        'fields' => [
            ['id' => 'fld_respondedat', 'slug' => 'responded_at', 'name' => 'Responded', 'type' => 'datetime'],
            ['id' => 'fld_npsvalue000', 'slug' => 'nps', 'name' => 'Nps', 'type' => 'number'],
            ['id' => 'fld_segment0000', 'slug' => 'segment', 'name' => 'Segment', 'type' => 'string'],
        ],
        'source' => [
            'type' => 'connected',
            'operations' => ['list' => array_filter([
                'mcp_tool' => 'get-nps-comments-tool',
                'arguments' => $mode !== null ? ['mode' => $mode] : null,
                'collection_path' => 'comments',
            ], fn ($v) => $v !== null)],
        ],
    ];
}

/** Four distinct days so the trend's bucket count clears the >= 3 threshold. */
function dss_comments_rows(): array
{
    return collect(range(0, 7))->map(fn (int $i) => [
        'responded_at' => now()->utc()->subDays($i)->toIso8601String(),
        'nps' => 6 + ($i % 5),
        'segment' => $i % 2 === 0 ? 'promoter' : 'detractor',
    ])->all();
}

it('features a measure the prompt NAMED that lives in a field, not a tool (nps on a ticket list)', function () {
    // Prod app_…thpsg: "dashboard de NPS de yuhu" acquired a ticket list that
    // CARRIES nps_score as a field, but the board headlined ticket volume and
    // never surfaced nps_score. The prompt topic now leads the band + trend.
    $object = [
        'id' => 'obj_tickets00', 'slug' => 'search_tickets', 'name' => 'Search Tickets',
        'fields' => [
            ['id' => 'fld_created0', 'slug' => 'created_at', 'name' => 'Created', 'type' => 'datetime'],
            ['id' => 'fld_status00', 'slug' => 'status', 'name' => 'Status', 'type' => 'string'],
            ['id' => 'fld_npsscore', 'slug' => 'nps_score', 'name' => 'Nps Score', 'type' => 'number'],
            ['id' => 'fld_totaltix', 'slug' => 'total_tickets', 'name' => 'Total Tickets', 'type' => 'number'],
        ],
        'source' => ['field_map' => [
            ['field_id' => 'fld_created0', 'external_path' => 'created_at'],
            ['field_id' => 'fld_status00', 'external_path' => 'status'],
            ['field_id' => 'fld_npsscore', 'external_path' => 'nps_score'],
            ['field_id' => 'fld_totaltix', 'external_path' => 'total_tickets'],
        ]],
    ];
    $rows = collect(range(0, 9))->map(fn (int $i) => [
        'created_at' => now()->utc()->subDays($i)->toIso8601String(),
        'status' => $i % 2 ? 'open' : 'closed',
        'nps_score' => 6 + ($i % 5),
        'total_tickets' => 10 + $i,
    ])->all();

    $spec = app(DashboardSpecSuggester::class)->suggest($object, 'es', $rows, ['nps', 'yuhu']);

    // A KPI averages nps_score — the measure the user asked for.
    $npsKpi = collect($spec['kpis'])->firstWhere('field_id', 'fld_npsscore');
    expect($npsKpi)->not->toBeNull()
        ->and($npsKpi['aggregation'])->toBe('avg');

    // The trend charts nps_score over time, not a raw ticket count.
    $trend = collect($spec['charts'])->first(fn (array $c): bool => isset($c['x_field_id']));
    expect($trend)->not->toBeNull()
        ->and($trend['y_field_id'] ?? null)->toBe('fld_npsscore')
        ->and($trend['aggregation'])->toBe('avg');
});

it('never charts a recency-capped source as a count-over-time trend', function () {
    $suggester = app(DashboardSpecSuggester::class);

    $countTrend = fn (array $spec): bool => collect($spec['charts'] ?? [])->contains(
        fn (array $c): bool => ($c['aggregation'] ?? null) === 'count' && isset($c['x_field_id']),
    );

    // mode:latest → the volume line is skipped (it would plot the sampling window).
    $capped = $suggester->suggest(dss_comments_object('latest'), 'es', dss_comments_rows());
    expect($countTrend($capped))->toBeFalse();

    // The same object WITHOUT the cap does get the count trend — proving it's the
    // cap that suppresses it, not a missing date field or too few buckets.
    $uncapped = $suggester->suggest(dss_comments_object(null), 'es', dss_comments_rows());
    expect($countTrend($uncapped))->toBeTrue();
});

it('labels a measure KPI with the clean field name, not a "Suma/Promedio" prefix', function () {
    $spec = app(DashboardSpecSuggester::class)->suggest(dss_comments_object(null), 'es', dss_comments_rows());

    $npsKpi = collect($spec['kpis'] ?? [])->firstWhere('field_id', 'fld_npsvalue000');

    expect($npsKpi)->not->toBeNull()
        ->and($npsKpi['label'])->toBe('Nps')
        ->and($npsKpi['label'])->not->toStartWith('Promedio ')
        ->and($npsKpi['label'])->not->toStartWith('Suma ');
});

/** A weekly pre-aggregated series like the prod contact-rate tool returns. */
function dss_weekly_series(string $idSuffix, string $slug, string $measureSlug, int $weeks): array
{
    return [
        'object' => [
            'id' => 'obj_'.$idSuffix,
            'slug' => $slug,
            'name' => Str::headline($slug),
            'fields' => [
                ['id' => 'fld_pstart'.$idSuffix, 'slug' => 'period_start', 'name' => 'Period Start', 'type' => 'date'],
                ['id' => 'fld_plabel'.$idSuffix, 'slug' => 'period_label', 'name' => 'Period Label', 'type' => 'string'],
                ['id' => 'fld_measur'.$idSuffix, 'slug' => $measureSlug, 'name' => Str::headline($measureSlug), 'type' => 'number'],
                ['id' => 'fld_orders'.$idSuffix, 'slug' => 'ordenes', 'name' => 'Ordenes', 'type' => 'number'],
            ],
            'source' => [
                'type' => 'connected',
                'field_map' => [
                    ['field_id' => 'fld_pstart'.$idSuffix, 'external_path' => 'period_start'],
                    ['field_id' => 'fld_plabel'.$idSuffix, 'external_path' => 'period_label'],
                    ['field_id' => 'fld_measur'.$idSuffix, 'external_path' => $measureSlug],
                    ['field_id' => 'fld_orders'.$idSuffix, 'external_path' => 'ordenes'],
                ],
                'operations' => ['list' => ['mcp_tool' => 'get-'.str_replace('_', '-', $slug).'-tool', 'collection_path' => 'series']],
            ],
        ],
        'rows' => collect(range(0, $weeks - 1))->map(fn (int $w) => [
            'period_start' => now()->utc()->subWeeks($w)->startOfWeek()->toDateString(),
            'period_label' => 'Semana '.($w + 1),
            $measureSlug => 100 + $w * 7,
            'ordenes' => 40 + $w * 3,
        ])->all(),
    ];
}

it('a time series with too few buckets gets a bar per period, never axis-less charts or a radar', function () {
    // Prod app dashyuhu: csc_contact_rate sampled only 2 weekly periods → the
    // trend guard (< 3 buckets) skipped the line, period_label was filtered
    // from breakdowns, and the old fallback shipped THREE charts with no
    // group_by and no x axis (bar/hbar/radar) — each a single aggregated
    // value, the radar rendering nothing at all.
    ['object' => $object, 'rows' => $rows] = dss_weekly_series('cr', 'csc_contact_rate', 'tickets_creados', 2);

    $spec = app(DashboardSpecSuggester::class)->suggest($object, 'es', $rows, ['tickets']);

    expect($spec['charts'])->not->toBeEmpty();
    foreach ($spec['charts'] as $chart) {
        $hasAxis = isset($chart['x_field_id']) || isset($chart['group_by_field_id']) || ($chart['chart_type'] ?? '') === 'box';
        expect($hasAxis)->toBeTrue()
            ->and($chart['chart_type'])->not->toBe('radar');
    }

    // The honest form: the lead measure as one bar per period on the DATE
    // axis (few periods ⇒ few bars, still a real picture). Never group_by the
    // bucket-label column — that is the exact shape the compiler refuses
    // (illegal_aggregation, "the time axis in costume"), and it killed a
    // whole prod build once (ticketcsc, run plr_01kx4jxx…).
    $perPeriod = collect($spec['charts'])->firstWhere('x_field_id', 'fld_pstartcr');
    expect($perPeriod)->not->toBeNull()
        ->and($perPeriod['chart_type'])->toBe('bar')
        ->and($perPeriod['y_field_id'])->toBe('fld_measurcr')
        ->and($perPeriod['bucket'])->toBe('week')
        ->and(collect($spec['charts'])->pluck('group_by_field_id')->filter()->values()->all())
        ->not->toContain('fld_plabelcr');
});

it('a degenerate series\' suggested spec survives the compiler and its lints end to end', function () {
    // The suggester and the compiler are two halves of ONE contract: whatever
    // the fallback ladder emits must compile and lint clean, because Express
    // banks this spec with zero model help. Prod ticketcsc: a weekly series
    // with a single sampled bucket made the fallback group by period_label,
    // the compiler refused its own pipeline's suggestion, and the build died
    // before any dashboard version existed.
    ['object' => $object, 'rows' => $rows] = dss_weekly_series('dg', 'csc_contact_rate', 'tickets_creados', 1);

    $spec = app(DashboardSpecSuggester::class)->suggest($object, 'es', $rows, ['tickets']);

    $built = app(AppScaffolder::class)->buildDashboardFromSpec(
        $spec, $object, [], ColorPalette::fromAccent('#00ce7c'), 'es',
    );
    expect($built['ok'] ?? false)->toBeTrue();

    $lint = PlanDashboardTool::lint($built['purpose'], $built['plan_rows']);
    expect($lint['ok'])->toBeTrue();
});

it('deduplicates the same measure across objects: one tickets total, then each source\'s next metric', function () {
    // Prod dashyuhu: three sources each headlined "total tickets" → the band
    // showed three near-identical KPIs that could disagree with each other.
    $primary = dss_weekly_series('p1', 'csc_contact_rate', 'tickets_creados', 6);
    $secondary = [
        'object' => [
            'id' => 'obj_dim1', 'slug' => 'tickets_by_dimension', 'name' => 'Tickets By Dimension',
            'fields' => [
                ['id' => 'fld_key0dim1', 'slug' => 'key', 'name' => 'Key', 'type' => 'string'],
                ['id' => 'fld_ttotdim1', 'slug' => 'totals_total_tickets', 'name' => 'Total Tickets', 'type' => 'number'],
                ['id' => 'fld_backdim1', 'slug' => 'totals_backlog_open', 'name' => 'Backlog Open', 'type' => 'number'],
            ],
            'source' => [
                'type' => 'connected',
                'field_map' => [
                    ['field_id' => 'fld_key0dim1', 'external_path' => 'key'],
                    ['field_id' => 'fld_ttotdim1', 'external_path' => 'totals.total_tickets'],
                    ['field_id' => 'fld_backdim1', 'external_path' => 'totals.backlog_open'],
                ],
                'operations' => ['list' => ['mcp_tool' => 'get-tickets-by-dimension-tool', 'collection_path' => 'breakdown']],
            ],
        ],
        'rows' => [
            ['key' => 'Envíos', 'totals' => ['total_tickets' => 40, 'backlog_open' => 5]],
            ['key' => 'Pagos', 'totals' => ['total_tickets' => 25, 'backlog_open' => 2]],
            ['key' => 'Cuenta', 'totals' => ['total_tickets' => 12, 'backlog_open' => 1]],
        ],
    ];

    $spec = app(DashboardSpecSuggester::class)->suggestMulti(
        [$primary['object'], $secondary['object']],
        'es',
        [
            $primary['object']['id'] => $primary['rows'],
            $secondary['object']['id'] => $secondary['rows'],
        ],
        ['tickets'],
    );

    // Exactly ONE tickets-volume KPI on the whole band — the primary's.
    $ticketVolume = collect($spec['kpis'])->filter(
        fn (array $k): bool => in_array($k['aggregation'] ?? '', ['sum', 'count'], true)
            && in_array($k['field_id'] ?? '', ['fld_measurp1', 'fld_ttotdim1'], true),
    );
    expect($ticketVolume)->toHaveCount(1);

    // The secondary still contributes — its NEXT distinct metric (backlog).
    $backlog = collect($spec['kpis'])->firstWhere('field_id', 'fld_backdim1');
    expect($backlog)->not->toBeNull()
        ->and($backlog['object_slug'] ?? null)->toBe('tickets_by_dimension');
});

it('falls back to a box distribution on raw rows with no date and no categorical', function () {
    $object = [
        'id' => 'obj_scores0', 'slug' => 'quality_scores', 'name' => 'Quality Scores',
        'fields' => [
            ['id' => 'fld_score000', 'slug' => 'inspection_minutes', 'name' => 'Inspection Minutes', 'type' => 'number'],
        ],
        'source' => ['field_map' => [['field_id' => 'fld_score000', 'external_path' => 'inspection_minutes']]],
    ];
    $rows = collect(range(1, 8))->map(fn (int $i) => ['inspection_minutes' => $i * 3.5])->all();

    $spec = app(DashboardSpecSuggester::class)->suggest($object, 'es', $rows);

    expect($spec['charts'])->toHaveCount(1)
        ->and($spec['charts'][0]['chart_type'])->toBe('box')
        ->and($spec['charts'][0]['y_field_id'])->toBe('fld_score000');
});

it('narrates insight bodies with real numbers at suggest time', function () {
    // Bank-first compiles the board BEFORE the model gates, so the suggested
    // insight bodies ARE what ships when the model can't answer. They must be
    // born factual (a prod board shipped the generic "Registros dentro de la
    // ventana…" scaffold because the factual narration only lived in the
    // gate's fallback, which the banked page never saw).
    $spec = app(DashboardSpecSuggester::class)->suggest(dss_comments_object(null), 'es', dss_comments_rows());

    $all = collect($spec['insights'])->pluck('body')->implode(' || ');
    // The analytical pack ranks ABOVE static aggregates: cards lead with the
    // self-split period delta and the trend slope, each carrying real numbers.
    expect($all)->toContain('vs la primera mitad del periodo') // PoP delta, real value
        ->and($all)->toContain('tendencia')                    // linear slope %/cadence
        ->and($all)->toContain('%');
});

it('decorates KPIs for honest display: fractions as percentage, 0-100 rates with a % unit', function () {
    $object = [
        'id' => 'obj_rates000', 'slug' => 'delivery_quality', 'name' => 'Delivery Quality',
        'fields' => [
            ['id' => 'fld_okfrac00', 'slug' => 'on_time_rate', 'name' => 'On Time Rate', 'type' => 'number'],
            ['id' => 'fld_containp', 'slug' => 'containment_pct', 'name' => 'Containment Pct', 'type' => 'number'],
        ],
        'source' => ['field_map' => [
            ['field_id' => 'fld_okfrac00', 'external_path' => 'on_time_rate'],
            ['field_id' => 'fld_containp', 'external_path' => 'containment_pct'],
        ]],
    ];
    $rows = collect(range(0, 5))->map(fn (int $i) => [
        'on_time_rate' => 0.85 + $i * 0.02,   // 0..1 fraction
        'containment_pct' => 80.5 + $i * 1.5, // already percent-scaled
    ])->all();

    $spec = app(DashboardSpecSuggester::class)->suggest($object, 'es', $rows);

    $fraction = collect($spec['kpis'])->firstWhere('field_id', 'fld_okfrac00');
    expect($fraction['format'] ?? null)->toBe('percentage')
        ->and($fraction['unit'] ?? null)->toBeNull();

    // 0-100 values with the percentage format would render 8050% — they stay
    // plain numbers and carry the % on the caption instead.
    $percent = collect($spec['kpis'])->firstWhere('field_id', 'fld_containp');
    expect($percent['format'] ?? null)->not->toBe('percentage')
        ->and($percent['unit'] ?? null)->toBe('%');
});

it('gives every dated KPI a previous-window compare and a semantic delta direction', function () {
    ['object' => $object, 'rows' => $rows] = dss_weekly_series('cmp', 'csc_contact_rate', 'tickets_creados', 6);
    // Add a measure whose good direction is DOWN (a backlog).
    $object['fields'][] = ['id' => 'fld_backlogcmp', 'slug' => 'totals_backlog_open', 'name' => 'Backlog Open', 'type' => 'number'];
    $object['source']['field_map'][] = ['field_id' => 'fld_backlogcmp', 'external_path' => 'backlog_open'];
    $rows = array_map(fn (array $r, int $i) => [...$r, 'backlog_open' => 5 + $i], $rows, array_keys($rows));

    $spec = app(DashboardSpecSuggester::class)->suggest($object, 'es', $rows, ['tickets']);

    // Every KPI carries the previous-window compare (the delta chip's source).
    foreach ($spec['kpis'] as $kpi) {
        $json = json_encode($kpi['compare'] ?? null);
        expect($json)->toContain('range_prev_start')
            ->and($json)->toContain('range_start')
            ->and($json)->toContain('fld_pstartcmp'); // the object's own date axis
    }

    // Semantic direction: a backlog falling is good.
    $backlog = collect($spec['kpis'])->firstWhere('field_id', 'fld_backlogcmp');
    expect($backlog)->not->toBeNull()
        ->and($backlog['delta_good'])->toBe('down');
});

it('keeps ONE KPI per measure inside a single band (count of rows vs sum of its total column)', function () {
    // A RAW ticket list that ALSO carries a total_tickets numeric column:
    // count(rows) and sum(total_tickets) are the same headline twice.
    $object = [
        'id' => 'obj_rawtix00', 'slug' => 'tickets', 'name' => 'Tickets',
        'fields' => [
            ['id' => 'fld_createdrt', 'slug' => 'created_at', 'name' => 'Created', 'type' => 'datetime'],
            ['id' => 'fld_totaltixr', 'slug' => 'total_tickets', 'name' => 'Total Tickets', 'type' => 'number'],
            ['id' => 'fld_statusrt0', 'slug' => 'status', 'name' => 'Status', 'type' => 'string'],
        ],
        'source' => ['field_map' => [
            ['field_id' => 'fld_createdrt', 'external_path' => 'created_at'],
            ['field_id' => 'fld_totaltixr', 'external_path' => 'total_tickets'],
            ['field_id' => 'fld_statusrt0', 'external_path' => 'status'],
        ]],
    ];
    $rows = collect(range(0, 7))->map(fn (int $i) => [
        'created_at' => now()->utc()->subDays($i)->toIso8601String(),
        'total_tickets' => 3 + $i,
        'status' => $i % 2 ? 'open' : 'closed',
    ])->all();

    $spec = app(DashboardSpecSuggester::class)->suggest($object, 'es', $rows);

    $volumeKpis = collect($spec['kpis'])->filter(
        fn (array $k): bool => in_array($k['aggregation'] ?? '', ['count', 'sum'], true)
            && ($k['filter'] ?? null) === null,
    );
    expect($volumeKpis)->toHaveCount(1);
});

it('emits several trends over a multi-measure series, each with its own axis and form', function () {
    // A pre-aggregated series carrying many measures (contact rate: tickets,
    // ordenes, containment) used to spend its whole time story on ONE line.
    ['object' => $object, 'rows' => $rows] = dss_weekly_series('mm', 'csc_contact_rate', 'tickets_creados', 26);
    $object['fields'][] = ['id' => 'fld_containmm', 'slug' => 'containment_pct', 'name' => 'Containment Pct', 'type' => 'number'];
    $object['source']['field_map'][] = ['field_id' => 'fld_containmm', 'external_path' => 'containment_pct'];
    $rows = array_map(fn (array $r, int $i) => [...$r, 'containment_pct' => 70.5 + ($i % 10)], $rows, array_keys($rows));

    $spec = app(DashboardSpecSuggester::class)->suggest($object, 'es', $rows, ['tickets']);

    $trends = collect($spec['charts'])->filter(fn (array $c): bool => isset($c['x_field_id']))->values();
    expect($trends->count())->toBeGreaterThanOrEqual(2);

    // Distinct measures, varied forms, never more than 2 of a kind.
    expect($trends->pluck('y_field_id')->unique()->count())->toBe($trends->count());
    $typeCounts = $trends->pluck('chart_type')->countBy();
    foreach ($typeCounts as $count) {
        expect($count)->toBeLessThanOrEqual(2);
    }
});

it('opens the board on a window the sampled data actually spans', function () {
    // ~26 weeks of data filtered to the fixed 30-day default rendered an
    // empty board; the span now picks the preset.
    ['object' => $long, 'rows' => $longRows] = dss_weekly_series('dr1', 'ventas_mensuales', 'monto_total', 26);
    $longSpec = app(DashboardSpecSuggester::class)->suggest($long, 'es', $longRows);
    expect($longSpec['default_range'] ?? null)->toBe('1y');

    ['object' => $mid, 'rows' => $midRows] = dss_weekly_series('dr2', 'ventas_trimestre', 'monto_total', 10);
    $midSpec = app(DashboardSpecSuggester::class)->suggest($mid, 'es', $midRows);
    expect($midSpec['default_range'] ?? null)->toBe('90d');

    // A fresh 2-week window keeps the product default.
    ['object' => $short, 'rows' => $shortRows] = dss_weekly_series('dr3', 'ventas_semana', 'monto_total', 2);
    $shortSpec = app(DashboardSpecSuggester::class)->suggest($short, 'es', $shortRows);
    expect($shortSpec['default_range'] ?? null)->toBe('30d');

    // The KPI compare window defaults to the SAME preset the board opens on.
    $kpi = $longSpec['kpis'][0];
    expect(json_encode($kpi['compare']))->toContain("'1y'");
});

it('leads a dimension breakdown that carries statistics with the statistic, not the volume donut', function () {
    // A resolution-time-by-category object: "avg_minutes por key" IS the
    // story; the volume donut follows it.
    $object = [
        'id' => 'obj_restime0', 'slug' => 'tickets_resolution_time', 'name' => 'Tickets Resolution Time',
        'fields' => [
            ['id' => 'fld_key0rt00', 'slug' => 'key', 'name' => 'Key', 'type' => 'string'],
            ['id' => 'fld_countrt0', 'slug' => 'count', 'name' => 'Count', 'type' => 'number'],
            ['id' => 'fld_avgminrt', 'slug' => 'avg_minutes', 'name' => 'Avg Minutes', 'type' => 'number'],
            ['id' => 'fld_p90minrt', 'slug' => 'p90_minutes', 'name' => 'P90 Minutes', 'type' => 'number'],
        ],
        'source' => [
            'type' => 'connected',
            'field_map' => [
                ['field_id' => 'fld_key0rt00', 'external_path' => 'key'],
                ['field_id' => 'fld_countrt0', 'external_path' => 'count'],
                ['field_id' => 'fld_avgminrt', 'external_path' => 'avg_minutes'],
                ['field_id' => 'fld_p90minrt', 'external_path' => 'p90_minutes'],
            ],
            'operations' => ['list' => ['mcp_tool' => 'get-resolution-tool', 'collection_path' => 'by_dimension']],
        ],
    ];
    $rows = [
        ['key' => 'Envíos', 'count' => 40, 'avg_minutes' => 320.5, 'p90_minutes' => 900.0],
        ['key' => 'Pagos', 'count' => 25, 'avg_minutes' => 180.2, 'p90_minutes' => 420.0],
        ['key' => 'Cuenta', 'count' => 12, 'avg_minutes' => 95.7, 'p90_minutes' => 210.0],
    ];

    $spec = app(DashboardSpecSuggester::class)->suggest($object, 'es', $rows);

    $first = $spec['charts'][0];
    expect($first['chart_type'])->toBe('hbar')
        ->and($first['y_field_id'])->toBe('fld_avgminrt')
        ->and($first['aggregation'])->toBe('avg');
});

it('sizes a pre-aggregated breakdown with the additive measure, and the compiler refuses count', function () {
    // Prod yuhuticket: «Total Tickets por Motivo», an hbar counting rows on a
    // one-row-per-reason source — every bar was 1, labeled as ticket totals.
    $object = [
        'id' => 'obj_rcb', 'slug' => 'tickets_reason_cause_breakdown', 'name' => 'Tickets Reason Cause Breakdown',
        'fields' => [
            ['id' => 'fld_reason0000', 'slug' => 'reason', 'name' => 'Reason', 'type' => 'string'],
            ['id' => 'fld_totaltix00', 'slug' => 'total_tickets', 'name' => 'Total Tickets', 'type' => 'number'],
            ['id' => 'fld_pcttotal00', 'slug' => 'pct_of_total', 'name' => 'Pct Of Total', 'type' => 'number'],
        ],
        'source' => [
            'type' => 'connected',
            'operations' => ['list' => ['mcp_tool' => 'get-tickets-reason-cause-breakdown-tool', 'collection_path' => 'reasons']],
        ],
    ];
    $rows = collect(['Duplicado', 'Retraso', 'Defecto', 'Cobro'])->map(fn ($r, $i) => [
        'reason' => $r, 'total_tickets' => 80 - $i * 15, 'pct_of_total' => 32.0 - $i * 6,
    ])->all();

    $spec = app(DashboardSpecSuggester::class)->suggest($object, 'es', $rows, ['tickets', 'motivos']);

    // Whatever the suggester emits: no count-sized breakdowns on this grain.
    foreach ($spec['charts'] as $chart) {
        if (isset($chart['group_by_field_id']) && ! isset($chart['x_field_id'])) {
            expect($chart['aggregation'])->not->toBe('count');
        }
    }

    // And the compiler enforces it against ANY author: a hand-written count
    // breakdown on the same object dies as illegal_aggregation.
    $built = app(AppScaffolder::class)->buildDashboardFromSpec(
        array_merge($spec, ['charts' => [[
            'label' => 'Total Tickets por Motivo',
            'chart_type' => 'hbar',
            'aggregation' => 'count',
            'group_by_field_id' => 'fld_reason0000',
        ]]]),
        $object, [], ColorPalette::fromAccent('#00ce7c'), 'es',
    );
    expect($built['ok'] ?? false)->toBeFalse()
        ->and(collect($built['errors'])->pluck('code'))->toContain('illegal_aggregation');
});

it('compiles a pareto over a real dimension and refuses one over a date', function () {
    $object = [
        'id' => 'obj_par', 'slug' => 'causas', 'name' => 'Causas',
        'fields' => [
            ['id' => 'fld_causekey00', 'slug' => 'causa', 'name' => 'Causa', 'type' => 'string'],
            ['id' => 'fld_causedate0', 'slug' => 'fecha', 'name' => 'Fecha', 'type' => 'date'],
            ['id' => 'fld_causetix00', 'slug' => 'total_tickets', 'name' => 'Total Tickets', 'type' => 'number'],
        ],
    ];
    $base = [
        'title' => 'Pareto de causas',
        'kpis' => [['label' => 'Tickets', 'aggregation' => 'sum', 'field_id' => 'fld_causetix00']],
        'insights' => [],
    ];
    $compile = fn (array $chart) => app(AppScaffolder::class)->buildDashboardFromSpec(
        $base + ['charts' => [$chart]], $object, [], ColorPalette::fromAccent('#00ce7c'), 'es',
    );

    $ok = $compile([
        'label' => 'Top causas', 'chart_type' => 'pareto', 'aggregation' => 'sum',
        'y_field_id' => 'fld_causetix00', 'group_by_field_id' => 'fld_causekey00', 'limit' => 15,
    ]);
    expect($ok['ok'] ?? false)->toBeTrue();

    $bad = $compile([
        'label' => 'Pareto por fecha', 'chart_type' => 'pareto', 'aggregation' => 'sum',
        'y_field_id' => 'fld_causetix00', 'group_by_field_id' => 'fld_causedate0',
    ]);
    expect($bad['ok'] ?? false)->toBeFalse()
        ->and(collect($bad['errors'])->pluck('code'))->toContain('degenerate_chart');
});

it('an explicit form intent in the ask shapes the flagship breakdown deterministically', function (array $topics, string $expected) {
    // The economy-mode complement: "pareto/top/distribución/compara" is a
    // finite vocabulary — intent shapes form without a model call.
    $object = [
        'id' => 'obj_intent', 'slug' => 'tickets_reason_cause_breakdown', 'name' => 'Tickets Reason Cause Breakdown',
        'fields' => [
            ['id' => 'fld_intreason0', 'slug' => 'reason', 'name' => 'Reason', 'type' => 'string'],
            ['id' => 'fld_inttotal00', 'slug' => 'total_tickets', 'name' => 'Total Tickets', 'type' => 'number'],
        ],
    ];
    $rows = collect(['Duplicado', 'Retraso', 'Defecto', 'Cobro'])->map(fn ($r, $i) => [
        'reason' => $r, 'total_tickets' => 80 - $i * 15,
    ])->all();

    $spec = app(DashboardSpecSuggester::class)->suggest($object, 'es', $rows, $topics);

    $flagship = collect($spec['charts'])->first(
        fn (array $c): bool => isset($c['group_by_field_id']) && ! isset($c['x_field_id']),
    );
    expect($flagship)->not->toBeNull()
        ->and($flagship['chart_type'])->toBe($expected);

    // Whatever the intent produced must survive the compiler and its lints.
    $built = app(AppScaffolder::class)->buildDashboardFromSpec(
        $spec, $object, [], ColorPalette::fromAccent('#00ce7c'), 'es',
    );
    expect($built['ok'] ?? false)->toBeTrue()
        ->and(PlanDashboardTool::lint($built['purpose'], $built['plan_rows'])['ok'])->toBeTrue();
})->with([
    'pareto' => [['tickets', 'pareto'], 'pareto'],
    'acumulado' => [['causas', 'acumulado'], 'pareto'],
    'ranking' => [['tickets', 'top'], 'hbar'],
    'distribución' => [['tickets', 'distribucion'], 'donut'],
    'comparación' => [['tickets', 'compara'], 'bar'],
    'sin intención (default)' => [['tickets'], 'donut'],
]);

it('embudo builds a funnel from sampled stage values and compiles to the dedicated block', function () {
    $object = [
        'id' => 'obj_funl', 'slug' => 'tickets_reason_cause_breakdown', 'name' => 'Tickets Reason Cause Breakdown',
        'fields' => [
            ['id' => 'fld_funreason0', 'slug' => 'reason', 'name' => 'Reason', 'type' => 'string'],
            ['id' => 'fld_funtotal00', 'slug' => 'total_tickets', 'name' => 'Total Tickets', 'type' => 'number'],
        ],
    ];
    $rows = collect(['Duplicado', 'Retraso', 'Defecto', 'Cobro'])->map(fn ($r, $i) => [
        'reason' => $r, 'total_tickets' => 80 - $i * 15,
    ])->all();

    $spec = app(DashboardSpecSuggester::class)->suggest($object, 'es', $rows, ['tickets', 'embudo']);

    $funnel = collect($spec['charts'])->firstWhere('chart_type', 'funnel');
    expect($funnel)->not->toBeNull()
        ->and($funnel['stages'])->toBe(['Duplicado', 'Retraso', 'Defecto', 'Cobro']);

    $built = app(AppScaffolder::class)->buildDashboardFromSpec(
        $spec, $object, [], ColorPalette::fromAccent('#00ce7c'), 'es',
    );
    expect($built['ok'] ?? false)->toBeTrue()
        ->and(PlanDashboardTool::lint($built['purpose'], $built['plan_rows'])['ok'])->toBeTrue();

    // The compiled page carries the DEDICATED funnel block: one eq-filtered
    // sum stage per sampled value, never a chart block with a funnel costume.
    $blocks = [];
    $walk = function (array $nodes) use (&$walk, &$blocks): void {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $blocks[] = $node;
            $walk($node['blocks'] ?? []);
        }
    };
    $walk($built['page']['blocks']);
    $block = collect($blocks)->firstWhere('type', 'funnel');
    expect($block)->not->toBeNull()
        ->and($block['stages'])->toHaveCount(4)
        ->and($block['stages'][0]['aggregation'])->toBe('sum')
        ->and($block['stages'][0]['field_id'])->toBe('fld_funtotal00')
        ->and(json_encode($block['stages'][0]['query']['filter']))->toContain('Duplicado');
});

it('mapa de calor emits the calendar heatmap only where dated record-level rows can answer it', function () {
    $topics = ['nps', 'mapa', 'calor'];

    // Raw rows + datetime + no cap → the heatmap chart entry appears and
    // compiles to the dedicated block.
    $spec = app(DashboardSpecSuggester::class)->suggest(dss_comments_object(null), 'es', dss_comments_rows(), $topics);
    $heat = collect($spec['charts'])->firstWhere('chart_type', 'heatmap');
    expect($heat)->not->toBeNull()
        ->and($heat['x_field_id'])->toBe('fld_respondedat');

    $built = app(AppScaffolder::class)->buildDashboardFromSpec(
        $spec, dss_comments_object(null), [], ColorPalette::fromAccent('#00ce7c'), 'es',
    );
    expect($built['ok'] ?? false)->toBeTrue()
        ->and(json_encode($built['page']['blocks']))->toContain('"type":"heatmap"');

    // A recency-capped sample would plot the sampling window — no heatmap.
    $capped = app(DashboardSpecSuggester::class)->suggest(dss_comments_object('latest'), 'es', dss_comments_rows(), $topics);
    expect(collect($capped['charts'])->firstWhere('chart_type', 'heatmap'))->toBeNull();
});

it('insights are born with period-over-period deltas when the previous window was sampled', function () {
    $object = [
        'id' => 'obj_pop', 'slug' => 'tickets_reason_cause_breakdown', 'name' => 'Tickets Reason Cause Breakdown',
        'fields' => [
            ['id' => 'fld_popreason0', 'slug' => 'reason', 'name' => 'Reason', 'type' => 'string'],
            ['id' => 'fld_poptotal00', 'slug' => 'total_tickets', 'name' => 'Total Tickets', 'type' => 'number'],
        ],
        'source' => ['field_map' => [
            ['field_id' => 'fld_popreason0', 'external_path' => 'reason'],
            ['field_id' => 'fld_poptotal00', 'external_path' => 'total_tickets'],
        ]],
    ];
    $rows = [
        ['reason' => 'Duplicado', 'total_tickets' => 60],
        ['reason' => 'Retraso', 'total_tickets' => 40],
    ]; // actual: 100
    $previous = [
        ['reason' => 'Duplicado', 'total_tickets' => 50],
        ['reason' => 'Retraso', 'total_tickets' => 30],
    ]; // anterior: 80 → +25%

    $spec = app(DashboardSpecSuggester::class)->suggest($object, 'es', $rows, ['tickets'], $previous);

    $bodies = collect($spec['insights'])->pluck('body')->implode(' ');
    expect($bodies)->toContain('vs el periodo anterior')
        ->and($bodies)->toContain('+25');
});

it('a dated series computes its delta from its own halves — no second fetch needed', function () {
    ['object' => $object] = dss_weekly_series('pp', 'tickets_semanales', 'total_tickets', 8);
    // 8 weekly rows: older half 100..121, recent half 128..149 → recent sums higher.
    $rows = collect(range(0, 7))->map(fn (int $w) => [
        'period_start' => now()->utc()->subWeeks(7 - $w)->startOfWeek()->toDateString(),
        'period_label' => 'Semana '.($w + 1),
        'total_tickets' => 100 + $w * 7,
        'ordenes' => 40,
    ])->all();

    $facts = app(ComputedFactsBuilder::class)->build($object, $rows);

    $pop = $facts['vs_periodo_anterior'] ?? null;
    expect($pop)->not->toBeNull()
        ->and($pop['base'])->toBe('mitades')
        ->and($pop['measures']['Total Tickets']['delta_pct'])->toBeGreaterThan(0);
});

it('a dateless windowed source gets a live previous-window compare on its KPIs', function () {
    $object = [
        'id' => 'obj_cw', 'slug' => 'tickets_by_dimension', 'name' => 'Tickets By Dimension',
        'fields' => [
            ['id' => 'fld_cwk', 'slug' => 'key', 'name' => 'Key', 'type' => 'string'],
            ['id' => 'fld_cwt', 'slug' => 'total_tickets', 'name' => 'Total Tickets', 'type' => 'number'],
        ],
        'source' => [
            'type' => 'connected',
            'operations' => ['list' => ['mcp_tool' => 'get-tickets-by-dimension-tool', 'arguments' => ['from' => '{{days_ago(30)}}', 'to' => '{{today()}}'], 'collection_path' => 'breakdown']],
        ],
    ];
    $rows = [['key' => 'A', 'total_tickets' => 60], ['key' => 'B', 'total_tickets' => 40]];

    $spec = app(DashboardSpecSuggester::class)->suggest($object, 'es', $rows, ['tickets']);

    $kpi = collect($spec['kpis'])->firstWhere('field_id', 'fld_cwt');
    expect($kpi)->not->toBeNull()
        ->and($kpi['compare_window'] ?? null)->toBe('previous')
        ->and($kpi)->not->toHaveKey('compare');
});

it('drops a chart that shows exactly the same information, whatever its chart_type', function () {
    // Prod yuhuticket: «Total Tickets por reason» (bar, avg) beside «Total
    // Tickets por Motivo» (hbar) — same measure over the same dimension of
    // the same object. On a one-row-per-category grain sum/avg collapse to
    // the same numbers, so a different aggregation or type is still the same
    // information. The duplicate is dropped; different dimensions survive.
    $object = [
        'id' => 'obj_dup', 'slug' => 'tickets_reason_cause_breakdown', 'name' => 'Tickets Reason Cause Breakdown',
        'fields' => [
            ['id' => 'fld_dupreason0', 'slug' => 'reason', 'name' => 'Reason', 'type' => 'string'],
            ['id' => 'fld_dupcanal00', 'slug' => 'canal', 'name' => 'Canal', 'type' => 'string'],
            ['id' => 'fld_duptotal00', 'slug' => 'total_tickets', 'name' => 'Total Tickets', 'type' => 'number'],
        ],
    ];
    $built = app(AppScaffolder::class)->buildDashboardFromSpec([
        'title' => 'Duplicados',
        'kpis' => [['label' => 'Tickets', 'aggregation' => 'sum', 'field_id' => 'fld_duptotal00']],
        'insights' => [],
        'charts' => [
            ['label' => 'Total Tickets por reason', 'chart_type' => 'bar', 'aggregation' => 'avg', 'y_field_id' => 'fld_duptotal00', 'group_by_field_id' => 'fld_dupreason0'],
            ['label' => 'Total Tickets por Motivo', 'chart_type' => 'hbar', 'aggregation' => 'sum', 'y_field_id' => 'fld_duptotal00', 'group_by_field_id' => 'fld_dupreason0'],
            ['label' => 'Por canal', 'chart_type' => 'donut', 'aggregation' => 'sum', 'y_field_id' => 'fld_duptotal00', 'group_by_field_id' => 'fld_dupcanal00'],
        ],
    ], $object, [], ColorPalette::fromAccent('#00ce7c'), 'es');

    expect($built['ok'])->toBeTrue();
    $charts = collect(json_decode(json_encode($built['page']['blocks']), true))
        ->flatten(2)->filter(fn ($b) => is_array($b) && ($b['type'] ?? null) === 'chart');
    $labels = [];
    $walk = function (array $nodes) use (&$walk, &$labels): void {
        foreach ($nodes as $n) {
            if (! is_array($n)) {
                continue;
            }
            if (($n['type'] ?? null) === 'chart') {
                $labels[] = $n['label'];
            }
            $walk($n['blocks'] ?? []);
        }
    };
    $labels = [];
    $walk($built['page']['blocks']);
    expect($labels)->toContain('Total Tickets por reason')
        ->and($labels)->toContain('Por canal')
        ->and($labels)->not->toContain('Total Tickets por Motivo'); // the duplicate died
});

it('every compiled chart carries an executive description; a spec-provided one wins', function () {
    $object = [
        'id' => 'obj_desc', 'slug' => 'causas', 'name' => 'Causas',
        'fields' => [
            ['id' => 'fld_desccausa0', 'slug' => 'causa', 'name' => 'Causa', 'type' => 'string'],
            ['id' => 'fld_desctix000', 'slug' => 'total_tickets', 'name' => 'Total Tickets', 'type' => 'number'],
        ],
    ];
    $built = app(AppScaffolder::class)->buildDashboardFromSpec([
        'title' => 'Descripciones',
        'kpis' => [['label' => 'Tickets', 'aggregation' => 'sum', 'field_id' => 'fld_desctix000']],
        'insights' => [],
        'charts' => [
            ['label' => 'Top causas', 'chart_type' => 'pareto', 'aggregation' => 'sum', 'y_field_id' => 'fld_desctix000', 'group_by_field_id' => 'fld_desccausa0'],
            ['label' => 'Con descripción propia', 'chart_type' => 'donut', 'aggregation' => 'sum', 'y_field_id' => 'fld_desctix000', 'group_by_field_id' => 'fld_desccausa0', 'filter' => ['op' => 'neq', 'field_id' => 'fld_desccausa0', 'value' => 'Otros'], 'description' => 'Reparto excluyendo «Otros».'],
        ],
    ], $object, [], ColorPalette::fromAccent('#00ce7c'), 'es');

    expect($built['ok'])->toBeTrue();
    $charts = [];
    $walk = function (array $nodes) use (&$walk, &$charts): void {
        foreach ($nodes as $n) {
            if (! is_array($n)) {
                continue;
            }
            if (($n['type'] ?? null) === 'chart') {
                $charts[$n['label']] = $n['description'] ?? null;
            }
            $walk($n['blocks'] ?? []);
        }
    };
    $walk($built['page']['blocks']);

    expect($charts['Top causas'])->toContain('% acumulado')
        ->and($charts['Top causas'])->toContain('causa')
        ->and($charts['Con descripción propia'])->toBe('Reparto excluyendo «Otros».');
});

it('the analytical pack computes anomaly, concentration and slope — with honest guards', function () {
    $builder = app(ComputedFactsBuilder::class);

    // Anomaly: a weekly series with one spike ≥2σ.
    ['object' => $series] = dss_weekly_series('an', 'tickets_semanales', 'total_tickets', 8);
    $rows = collect(range(0, 7))->map(fn (int $w) => [
        'period_start' => now()->utc()->subWeeks(7 - $w)->startOfWeek()->toDateString(),
        'period_label' => 'S'.($w + 1),
        'total_tickets' => $w === 5 ? 400 : 100, // the spike
        'ordenes' => 40,
    ])->all();
    $anomaly = $builder->build($series, $rows)['anomalia'] ?? null;
    expect($anomaly)->not->toBeNull()
        ->and($anomaly['valor'])->toEqual(400)
        ->and($anomaly['direccion'])->toBe('sobre')
        ->and($anomaly['z'])->toBeGreaterThanOrEqual(2);

    // Concentration: 2 of 6 categories carry >50% of the additive measure.
    $breakdown = [
        'id' => 'obj_conc', 'slug' => 'causas_breakdown', 'name' => 'Causas Breakdown',
        'fields' => [
            ['id' => 'fld_conccausa', 'slug' => 'causa', 'name' => 'Causa', 'type' => 'string'],
            ['id' => 'fld_conctix00', 'slug' => 'total_tickets', 'name' => 'Total Tickets', 'type' => 'number'],
        ],
        'source' => ['field_map' => [
            ['field_id' => 'fld_conccausa', 'external_path' => 'causa'],
            ['field_id' => 'fld_conctix00', 'external_path' => 'total_tickets'],
        ]],
    ];
    $concRows = collect(['A' => 40, 'B' => 25, 'C' => 10, 'D' => 10, 'E' => 8, 'F' => 7])
        ->map(fn ($v, $k) => ['causa' => $k, 'total_tickets' => $v])->values()->all();
    $conc = $builder->build($breakdown, $concRows)['concentracion'] ?? null;
    expect($conc)->not->toBeNull()
        ->and($conc['top'])->toBe(2)
        ->and($conc['total_categorias'])->toBe(6)
        ->and($conc['pct'])->toEqual(65.0)
        ->and($conc['lideres'])->toBe(['A', 'B']);

    // Slope: a steadily climbing series reports a positive %/semana.
    $climb = collect(range(0, 5))->map(fn (int $w) => [
        'period_start' => now()->utc()->subWeeks(5 - $w)->startOfWeek()->toDateString(),
        'period_label' => 'S'.($w + 1),
        'total_tickets' => 100 + $w * 20,
        'ordenes' => 40,
    ])->all();
    $slope = $builder->build($series, $climb)['tendencia'] ?? null;
    expect($slope)->not->toBeNull()
        ->and($slope['pendiente_pct'])->toBeGreaterThan(0)
        ->and($slope['cadencia'])->toBe('semana');

    // Guards: a flat short series reports none of the three.
    $flat = $builder->build($series, array_slice($climb, 0, 3));
    expect($flat)->not->toHaveKey('anomalia')
        ->and($flat)->not->toHaveKey('tendencia');
});

it('crossFacts reports a strong co-movement between two aligned series, as a lead not a cause', function () {
    $mk = fn (string $suffix, string $slug, string $measure) => dss_weekly_series($suffix, $slug, $measure, 6)['object'];
    $a = $mk('ca', 'tickets_semanales', 'total_tickets');
    $b = $mk('cb', 'quejas_semanales', 'total_quejas');

    $weeks = collect(range(0, 5))->map(fn (int $w) => now()->utc()->subWeeks(5 - $w)->startOfWeek()->toDateString());
    $rowsA = $weeks->map(fn ($d, $i) => ['period_start' => $d, 'period_label' => 'S'.$i, 'total_tickets' => 100 + $i * 10, 'ordenes' => 1])->all();
    $rowsB = $weeks->map(fn ($d, $i) => ['period_start' => $d, 'period_label' => 'S'.$i, 'total_quejas' => 200 - $i * 15, 'ordenes' => 1])->all();

    $cross = app(ComputedFactsBuilder::class)->crossFacts(
        [$a, $b],
        [$a['id'] => $rowsA, $b['id'] => $rowsB],
    );

    $sentence = collect($cross)->first(fn (string $f) => str_contains($f, '(r = '));
    expect($sentence)->not->toBeNull()
        ->and($sentence)->toContain('en sentidos opuestos')
        ->and($sentence)->toContain('no una causa probada');
});

it('interactivity base: category select filter wired to blocks, and a flagship detail table', function () {
    $object = [
        'id' => 'obj_int4', 'slug' => 'tickets_reason_cause_breakdown', 'name' => 'Tickets Reason Cause Breakdown',
        'fields' => [
            ['id' => 'fld_i4reason00', 'slug' => 'reason', 'name' => 'Reason', 'type' => 'string'],
            ['id' => 'fld_i4total000', 'slug' => 'total_tickets', 'name' => 'Total Tickets', 'type' => 'number'],
        ],
        'source' => ['field_map' => [
            ['field_id' => 'fld_i4reason00', 'external_path' => 'reason'],
            ['field_id' => 'fld_i4total000', 'external_path' => 'total_tickets'],
        ]],
    ];
    $rows = collect(['Duplicado', 'Retraso', 'Defecto', 'Cobro', 'Extravío', 'Garantía'])->map(fn ($r, $i) => [
        'reason' => $r, 'total_tickets' => 90 - $i * 10,
    ])->all();

    $spec = app(DashboardSpecSuggester::class)->suggest($object, 'es', $rows, ['tickets']);

    expect($spec['category_filter']['field_id'])->toBe('fld_i4reason00')
        ->and($spec['category_filter']['options'])->toContain('Duplicado')
        ->and($spec['table']['columns'][0])->toBe('fld_i4reason00')
        ->and($spec['table']['sort'][0]['direction'])->toBe('desc');

    $built = app(AppScaffolder::class)->buildDashboardFromSpec(
        $spec, $object, [], ColorPalette::fromAccent('#00ce7c'), 'es',
    );
    expect($built['ok'])->toBeTrue()
        ->and(PlanDashboardTool::lint($built['purpose'], $built['plan_rows'])['ok'])->toBeTrue();

    $page = json_encode($built['page']);
    expect($page)->toContain('"type":"select"')            // the filter control exists…
        ->and($page)->toContain('{{params.reason}}')       // …and blocks actually listen to it
        ->and($page)->toContain('"type":"table"')          // the detail table landed
        ->and($page)->toContain('"page_size":10');
});

it('the first dated KPIs carry a sparkline with the KPI own fold', function () {
    $spec = app(DashboardSpecSuggester::class)->suggest(dss_comments_object(null), 'es', dss_comments_rows(), ['nps']);

    $withSpark = collect($spec['kpis'])->filter(fn (array $k): bool => isset($k['spark']));
    expect($withSpark->count())->toBeGreaterThanOrEqual(1)
        ->and($withSpark->first()['spark']['x_field_id'])->toBe('fld_respondedat');

    $built = app(AppScaffolder::class)->buildDashboardFromSpec(
        $spec, dss_comments_object(null), [], ColorPalette::fromAccent('#00ce7c'), 'es',
    );
    expect($built['ok'])->toBeTrue()
        ->and(json_encode($built['page']))->toContain('"spark"');
});

it('"meta de 80%" leads the board with a gauge against the named target', function () {
    $object = [
        'id' => 'obj_meta', 'slug' => 'csc_contact_rate', 'name' => 'Csc Contact Rate',
        'fields' => [
            ['id' => 'fld_metadate00', 'slug' => 'period_start', 'name' => 'Period Start', 'type' => 'date'],
            ['id' => 'fld_metapct000', 'slug' => 'containment_pct', 'name' => 'Containment Pct', 'type' => 'number'],
            ['id' => 'fld_metatix000', 'slug' => 'total_tickets', 'name' => 'Total Tickets', 'type' => 'number'],
        ],
        'source' => ['field_map' => [
            ['field_id' => 'fld_metadate00', 'external_path' => 'period_start'],
            ['field_id' => 'fld_metapct000', 'external_path' => 'containment_pct'],
            ['field_id' => 'fld_metatix000', 'external_path' => 'total_tickets'],
        ]],
    ];
    $rows = collect(range(0, 5))->map(fn (int $w) => [
        'period_start' => now()->utc()->subWeeks(5 - $w)->startOfWeek()->toDateString(),
        'containment_pct' => 60 + $w, 'total_tickets' => 100 + $w,
    ])->all();

    $spec = app(DashboardSpecSuggester::class)->suggest(
        $object, 'es', $rows, ['contencion'], [], 'dashboard de contención con meta de 80%',
    );

    $gauge = collect($spec['charts'])->firstWhere('chart_type', 'gauge');
    expect($gauge)->not->toBeNull()
        ->and($gauge['max_value'])->toEqual(80.0)
        ->and($gauge['y_field_id'])->toBe('fld_metapct000')
        ->and($gauge['format'])->toBe('percentage');

    $built = app(AppScaffolder::class)->buildDashboardFromSpec(
        $spec, $object, [], ColorPalette::fromAccent('#00ce7c'), 'es',
    );
    expect($built['ok'])->toBeTrue()
        ->and(json_encode($built['page']))->toContain('"type":"gauge"')
        ->and(json_encode($built['page']))->toContain('"max_value":80');
});

it('an executive ask caps the board at four charts, keeping KPIs and insights whole', function () {
    ['object' => $object] = dss_weekly_series('ex', 'tickets_semanales', 'total_tickets', 8);
    $rows = collect(range(0, 7))->map(fn (int $w) => [
        'period_start' => now()->utc()->subWeeks(7 - $w)->startOfWeek()->toDateString(),
        'period_label' => 'S'.($w + 1),
        'total_tickets' => 100 + $w * 7,
        'ordenes' => 40 + $w,
    ])->all();

    $full = app(DashboardSpecSuggester::class)->suggestMulti([$object], 'es', [$object['id'] => $rows], ['tickets']);
    $exec = app(DashboardSpecSuggester::class)->suggestMulti([$object], 'es', [$object['id'] => $rows], ['tickets'], [], 'resumen ejecutivo de tickets');

    expect(count($exec['charts']))->toBeLessThanOrEqual(4)
        ->and(count($exec['charts']))->toBeLessThanOrEqual(count($full['charts']))
        ->and(count($exec['kpis']))->toBe(count($full['kpis']));
});

it('charts grouped by the filter field carry drill_param — click re-scopes the board', function () {
    $object = [
        'id' => 'obj_drill', 'slug' => 'tickets_reason_cause_breakdown', 'name' => 'Tickets Reason Cause Breakdown',
        'fields' => [
            ['id' => 'fld_drreason00', 'slug' => 'reason', 'name' => 'Reason', 'type' => 'string'],
            ['id' => 'fld_drtotal000', 'slug' => 'total_tickets', 'name' => 'Total Tickets', 'type' => 'number'],
        ],
        'source' => ['field_map' => [
            ['field_id' => 'fld_drreason00', 'external_path' => 'reason'],
            ['field_id' => 'fld_drtotal000', 'external_path' => 'total_tickets'],
        ]],
    ];
    $rows = collect(['A', 'B', 'C', 'D', 'E'])->map(fn ($r, $i) => [
        'reason' => $r, 'total_tickets' => 90 - $i * 10,
    ])->all();

    $spec = app(DashboardSpecSuggester::class)->suggest($object, 'es', $rows, ['tickets']);
    $built = app(AppScaffolder::class)->buildDashboardFromSpec(
        $spec, $object, [], ColorPalette::fromAccent('#00ce7c'), 'es',
    );
    expect($built['ok'])->toBeTrue();

    $charts = [];
    $walk = function (array $nodes) use (&$walk, &$charts): void {
        foreach ($nodes as $n) {
            if (! is_array($n)) {
                continue;
            }
            if (($n['type'] ?? null) === 'chart') {
                $charts[] = $n;
            }
            $walk($n['blocks'] ?? []);
        }
    };
    $walk($built['page']['blocks']);

    $byReason = collect($charts)->firstWhere('group_by_field_id', 'fld_drreason00');
    expect($byReason['drill_param'] ?? null)->toBe('reason');
});

it('a full suggested board VALIDATES against the manifest JSON schema', function () {
    // Compile-harness tests never ran schema validation, so compare_window
    // shipped into a metric_grid item def that did not allow it and prod died
    // at applyPatch (plr_01kx71cx). This pins the WHOLE chain: suggest →
    // compile → the page is legal manifest, filters, sparks, table, drill,
    // gauge and chips included.
    $object = [
        'id' => 'obj_schematest00', 'slug' => 'tickets_by_dimension', 'name' => 'Tickets By Dimension',
        'fields' => [
            ['id' => 'fld_sckey0000001', 'slug' => 'key', 'name' => 'Key', 'type' => 'string'],
            ['id' => 'fld_sctotal00001', 'slug' => 'total_tickets', 'name' => 'Total Tickets', 'type' => 'number'],
        ],
        'source' => [
            'type' => 'connected',
            'integration_id' => 'integ_x',
            'operations' => ['list' => ['mcp_tool' => 'get-tickets-by-dimension-tool', 'arguments' => ['from' => '{{days_ago(30)}}', 'to' => '{{today()}}'], 'collection_path' => 'breakdown']],
            'field_map' => [
                ['field_id' => 'fld_sckey0000001', 'external_path' => 'key'],
                ['field_id' => 'fld_sctotal00001', 'external_path' => 'totals.total_tickets'],
            ],
        ],
    ];
    $rows = collect(['A', 'B', 'C', 'D', 'E', 'F'])->map(fn ($k, $i) => [
        'key' => $k, 'totals' => ['total_tickets' => 100 - $i * 12],
    ])->all();

    $spec = app(DashboardSpecSuggester::class)->suggest($object, 'es', $rows, ['tickets']);
    $built = app(AppScaffolder::class)->buildDashboardFromSpec(
        $spec, $object, [], ColorPalette::fromAccent('#00ce7c'), 'es',
    );
    expect($built['ok'])->toBeTrue();

    $manifest = [
        'schema_version' => '1.0.0',
        'id' => 'app_schematest0000000000000',
        'slug' => 'schema_test', 'name' => 'Schema Test', 'version' => 1,
        'objects' => [$object],
        'pages' => [$built['page']],
        'permissions' => ['roles' => [['id' => 'rol_schematest0000000000000', 'name' => 'Admin', 'slug' => 'admin', 'is_default' => true]]],
    ];
    $result = app(ManifestValidator::class)->validate($manifest);

    expect($result->errorsArray())->toBe([])
        ->and($result->valid)->toBeTrue();
});
