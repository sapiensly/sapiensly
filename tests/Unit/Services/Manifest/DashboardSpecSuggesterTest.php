<?php

use App\Services\Manifest\DashboardSpecSuggester;
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

    // The honest form: the lead measure as one bar per period, on the
    // bucket-label axis (few periods ⇒ few bars, still a real picture).
    $perPeriod = collect($spec['charts'])->firstWhere('group_by_field_id', 'fld_plabelcr');
    expect($perPeriod)->not->toBeNull()
        ->and($perPeriod['chart_type'])->toBe('bar')
        ->and($perPeriod['y_field_id'])->toBe('fld_measurcr');
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
    expect($all)->toContain('promedio')     // a numeric measure fact with real values
        ->and($all)->toContain('concentra'); // a category concentration fact
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
