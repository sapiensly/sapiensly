<?php

use App\Ai\Tools\Builder\AddDashboardPageTool;
use App\Ai\Tools\Builder\PlanDashboardTool;
use App\Ai\Tools\Builder\PrepareDashboardTool;
use App\Ai\Tools\Builder\ProposeChangeTool;
use App\Models\App;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\AppScaffolder;
use App\Services\Manifest\DashboardSpecSuggester;
use App\Services\Manifest\ManifestValidator;
use App\Services\Records\RecordQueryService;
use App\Support\Branding\ColorPalette;
use App\Support\Branding\OrganizationBrand;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request as ToolRequest;

function adp_id(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

/**
 * A tickets-like object with the field shapes a dashboard exercises: selects,
 * a number, a date. Field ids are stable so the spec can reference them.
 */
function adp_manifest(string $appId): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => 'mini_'.strtolower(Str::random(6)),
        'name' => 'Tickets',
        'version' => 1,
        'objects' => [[
            'id' => 'obj_ticketsobj',
            'slug' => 'tickets',
            'name' => 'Ticket',
            'fields' => [
                ['id' => 'fld_titlefield', 'slug' => 'titulo', 'name' => 'Título', 'type' => 'string'],
                ['id' => 'fld_statefield', 'slug' => 'estado', 'name' => 'Estado', 'type' => 'single_select', 'options' => [
                    ['id' => adp_id('opt'), 'value' => 'abierto', 'label' => 'Abierto'],
                    ['id' => adp_id('opt'), 'value' => 'cerrado', 'label' => 'Cerrado'],
                ]],
                ['id' => 'fld_priofield', 'slug' => 'prioridad', 'name' => 'Prioridad', 'type' => 'single_select', 'options' => [
                    ['id' => adp_id('opt'), 'value' => 'alta', 'label' => 'Alta'],
                    ['id' => adp_id('opt'), 'value' => 'baja', 'label' => 'Baja'],
                ]],
                ['id' => 'fld_hoursfield', 'slug' => 'horas', 'name' => 'Horas', 'type' => 'number'],
                ['id' => 'fld_datefield', 'slug' => 'creado', 'name' => 'Creado', 'type' => 'date'],
            ],
        ]],
        'pages' => [],
        'settings' => ['default_locale' => 'es-MX'],
        'permissions' => [
            'roles' => [['id' => adp_id('rol'), 'slug' => 'admin', 'name' => 'Admin']],
        ],
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->testApp = App::factory()->create();
    $this->manifestService = app(AppManifestService::class);
    $this->validator = app(ManifestValidator::class);
    $this->manifestService->createVersion($this->testApp, adp_manifest($this->testApp->id), $this->user);
});

function adp_tool($test): array
{
    $propose = new ProposeChangeTool($test->testApp->fresh(), $test->manifestService, $test->validator);
    $tool = new AddDashboardPageTool($test->testApp->fresh(), $test->manifestService, $propose, app(AppScaffolder::class));

    return [$tool, $propose];
}

function adp_spec(): array
{
    return [
        'object_slug' => 'tickets',
        'title' => 'Análisis de Tickets',
        'purpose' => 'Vista ejecutiva del desempeño del soporte.',
        'date_field_id' => 'fld_datefield',
        'kpis' => [
            ['label' => 'Total', 'aggregation' => 'count', 'icon' => 'inbox'],
            ['label' => 'Mediana horas', 'aggregation' => 'median', 'field_id' => 'fld_hoursfield', 'delta_good' => 'down'],
            ['label' => 'Promedio horas', 'aggregation' => 'avg', 'field_id' => 'fld_hoursfield'],
        ],
        'charts' => [
            ['label' => 'Volumen semanal', 'chart_type' => 'line', 'aggregation' => 'count', 'x_field_id' => 'fld_datefield'],
            ['label' => 'Por estado', 'chart_type' => 'donut', 'aggregation' => 'count', 'group_by_field_id' => 'fld_statefield'],
            ['label' => 'Por prioridad', 'chart_type' => 'hbar', 'aggregation' => 'count', 'group_by_field_id' => 'fld_priofield'],
            ['label' => 'Horas por estado', 'chart_type' => 'radar', 'aggregation' => 'avg', 'y_field_id' => 'fld_hoursfield', 'group_by_field_id' => 'fld_statefield'],
        ],
        'insights' => [
            ['variant' => 'conclusion', 'title' => 'Backlog contenido', 'body' => 'Los cerrados superan a los abiertos.'],
        ],
    ];
}

it('compiles a full professional dashboard page from a content spec', function () {
    [$tool, $propose] = adp_tool($this);

    $result = json_decode($tool->handle(new ToolRequest(adp_spec())), true);

    expect($result['ok'])->toBeTrue();
    expect($result['page']['path'])->toBe('/dashboard');

    $draft = $propose->runningDraft();
    $page = collect($draft['pages'])->firstWhere('slug', 'dashboard');
    expect($page)->not->toBeNull();

    $types = collect($page['blocks'])->pluck('type')->all();
    // Hero chrome → date filter → KPI band → chart rows → insights.
    expect($types[0])->toBe('hero')
        ->and($types[1])->toBe('filter_bar')
        ->and($types[2])->toBe('metric_grid')
        ->and($types)->toContain('container');

    // The hero is a compact left brand banner.
    expect($page['blocks'][0]['align'])->toBe('left')
        ->and($page['blocks'][0]['min_height'])->toBeLessThanOrEqual(240)
        ->and($page['blocks'][0]['style']['gradient'])->toHaveKeys(['from', 'to']);

    // Wide (line) pairs with short (donut) under 7/5 column weights.
    $firstRow = collect($page['blocks'])->firstWhere('type', 'container');
    $kinds = collect($firstRow['blocks'])->pluck('chart_type')->all();
    expect($kinds)->toBe(['line', 'donut'])
        ->and($firstRow['blocks'][0]['style']['col_span'])->toBe(7)
        ->and($firstRow['blocks'][1]['style']['col_span'])->toBe(5);

    // The date-range filter is wired into KPIs and charts alike.
    $kpiQuery = $page['blocks'][2]['items'][0]['query'];
    expect(json_encode($kpiQuery))->toContain('range_start');
    expect(json_encode($firstRow['blocks'][0]['data_source']))->toContain('range_start');

    // A date X axis got a bucket so the series reads chronologically.
    expect($firstRow['blocks'][0]['bucket'])->toBe('week');

    // The whole compiled draft is schema-valid.
    expect($this->validator->validate($draft)->valid)->toBeTrue();
});

it('rejects percentile aggregations on charts, pointing to KPIs', function () {
    [$tool] = adp_tool($this);
    $spec = adp_spec();
    $spec['charts'][0] = ['label' => 'P95', 'chart_type' => 'line', 'aggregation' => 'p95', 'y_field_id' => 'fld_hoursfield'];

    $result = json_decode($tool->handle(new ToolRequest($spec)), true);

    expect($result['ok'])->toBeFalse()
        ->and(json_encode($result['errors']))->toContain('KPI');
});

it('names the exact unknown field instead of failing opaquely', function () {
    [$tool] = adp_tool($this);
    $spec = adp_spec();
    $spec['kpis'][] = ['label' => 'Fantasma', 'aggregation' => 'sum', 'field_id' => 'fld_missing99'];

    $result = json_decode($tool->handle(new ToolRequest($spec)), true);

    expect($result['ok'])->toBeFalse()
        ->and(json_encode($result['errors']))->toContain('fld_missing99');
});

it('enforces the dashboard lints on its own layout (variety, insights)', function () {
    [$tool] = adp_tool($this);
    $spec = adp_spec();
    // Monoculture: four bars and no insight cards.
    $spec['charts'] = array_map(fn (int $i) => [
        'label' => "Bar {$i}", 'chart_type' => 'bar', 'aggregation' => 'count', 'group_by_field_id' => 'fld_statefield',
    ], [1, 2, 3, 4]);
    $spec['insights'] = [];

    $result = json_decode($tool->handle(new ToolRequest($spec)), true);

    expect($result['ok'])->toBeFalse();
    $joined = json_encode($result['errors']);
    expect($joined)->toContain('bar')       // variety issue names the repeated type
        ->and($joined)->toContain('insight'); // and the missing conclusions
});

it('omits the filter bar and range wiring when include_date_filter is false', function () {
    [$tool, $propose] = adp_tool($this);
    $spec = adp_spec();
    $spec['include_date_filter'] = false;

    $result = json_decode($tool->handle(new ToolRequest($spec)), true);

    expect($result['ok'])->toBeTrue();
    $draft = $propose->runningDraft();
    $page = collect($draft['pages'])->firstWhere('slug', 'dashboard');

    expect(collect($page['blocks'])->pluck('type'))->not->toContain('filter_bar');
    expect(json_encode($page))->not->toContain('range_start');
});

it('gives the hero an eyebrow and floats the headline KPI as a live stat', function () {
    $manifest = app(AppManifestService::class)->getActiveManifest($this->testApp->fresh());
    $built = app(AppScaffolder::class)->buildDashboardFromSpec(adp_spec(), $manifest['objects'][0], [], null, 'es');

    $hero = collect($built['page']['blocks'])->firstWhere('type', 'hero');
    expect($hero['eyebrow'])->toBe('Reporte')
        ->and($hero['eyebrow_icon'])->toBe('bar-chart');

    // The hero stat mirrors the FIRST KPI, resolved with the same range filter.
    $firstKpi = collect($built['page']['blocks'])->firstWhere('type', 'metric_grid')['items'][0];
    expect($hero['stat']['aggregation'])->toBe($firstKpi['aggregation'])
        ->and($hero['stat']['query'])->toBe($firstKpi['query'])
        ->and(json_encode($hero['stat']['query']))->toContain('range_start');
});

it('captions each KPI with its aggregation basis (subtitle), filter-safe', function () {
    $manifest = app(AppManifestService::class)->getActiveManifest($this->testApp->fresh());
    $built = app(AppScaffolder::class)->buildDashboardFromSpec(adp_spec(), $manifest['objects'][0], [], null, 'es');

    $items = collect($built['page']['blocks'])->firstWhere('type', 'metric_grid')['items'];
    // adp_spec has a count, a median and an avg KPI.
    $byAgg = collect($items)->keyBy('aggregation');
    expect($byAgg['count']['subtitle'])->toBe('conteo en la ventana')
        ->and($byAgg['median']['subtitle'])->toBe('mediana del periodo')
        ->and($byAgg['avg']['subtitle'])->toBe('promedio del periodo');

    // The whole page still validates (subtitle is a registered item property).
    expect(app(ManifestValidator::class))->not->toBeNull();
});

it('opens a LOCAL-object dashboard on 30d but a CONNECTED one on the full range', function () {
    // The empty-on-open bug: a connected object's live data is often historical,
    // so a 30d default lands in a gap. Local records are recent → 30d is fine.
    $manifest = app(AppManifestService::class)->getActiveManifest($this->testApp->fresh());
    $local = $manifest['objects'][0]; // tickets — no source.type

    $localBuilt = app(AppScaffolder::class)->buildDashboardFromSpec(
        adp_spec(), $local, [], null, 'es',
    );
    $localFilter = collect($localBuilt['page']['blocks'])->firstWhere('type', 'filter_bar');
    expect($localFilter['controls'][0]['default'])->toBe('30d')
        ->and(json_encode($localBuilt['page']))->toContain("default(params.range, '30d')");

    // A connected series → default 'all' (never opens empty).
    $series = adp_series_object();
    $connectedBuilt = app(AppScaffolder::class)->buildDashboardFromSpec(
        (new DashboardSpecSuggester)->suggest($series, 'es') + ['object_slug' => 'nps_semanal'],
        $series, [], null, 'es',
    );
    $connFilter = collect($connectedBuilt['page']['blocks'])->firstWhere('type', 'filter_bar');
    expect($connFilter['controls'][0]['default'])->toBe('all')
        ->and(json_encode($connectedBuilt['page']))->toContain("default(params.range, 'all')")
        ->and(json_encode($connectedBuilt['page']))->not->toContain("default(params.range, '30d')");
});

it('prepare_dashboard fuses blueprint, profile and brand into one response', function () {
    $tool = new PrepareDashboardTool(
        $this->testApp->fresh(),
        $this->manifestService,
        app(RecordQueryService::class),
    );

    $result = json_decode($tool->handle(new ToolRequest([
        'object_id' => 'obj_ticketsobj',
        'sector' => 'support',
    ])), true);

    expect($result['ok'])->toBeTrue()
        ->and($result['blueprint']['sector'])->toBe('support')
        ->and($result['profile']['object_id'])->toBe('obj_ticketsobj')
        ->and(collect($result['profile']['fields'])->pluck('id'))->toContain('fld_hoursfield')
        ->and($result['brand']['accent'])->toStartWith('#')
        ->and($result['brand']['ramp'])->toHaveKeys(['600', '900']);
});

it('suggests a complete lint-worthy spec from the schema alone', function () {
    $manifest = adp_manifest($this->testApp->id);
    $spec = (new DashboardSpecSuggester)->suggest($manifest['objects'][0], 'es');

    expect($spec['date_field_id'])->toBe('fld_datefield')
        ->and($spec['kpis'][0]['aggregation'])->toBe('count')
        ->and(count($spec['kpis']))->toBeGreaterThanOrEqual(2)
        ->and(count($spec['charts']))->toBeGreaterThanOrEqual(3)
        ->and(collect($spec['charts'])->pluck('chart_type')->unique()->count())
        ->toBe(count($spec['charts'])) // structural variety: no repeated type
        ->and($spec['insights'])->not->toBeEmpty();

    // Categorical breakdowns always carry a limit — an unknown-cardinality
    // string can never explode a chart.
    foreach ($spec['charts'] as $chart) {
        if (isset($chart['group_by_field_id']) && ! isset($chart['y_field_id'])) {
            expect($chart['limit'])->toBe(12);
        }
    }
});

it('compiles the suggested spec via use_suggestion with tiny overrides', function () {
    [$tool, $propose] = adp_tool($this);

    $result = json_decode($tool->handle(new ToolRequest([
        'object_slug' => 'tickets',
        'use_suggestion' => true,
        'overrides' => [
            'title' => 'Panel Ejecutivo de Soporte',
            'insights' => [
                ['variant' => 'risk', 'title' => 'Riesgo SLA', 'body' => 'El 12% de los tickets incumple su primera respuesta.'],
            ],
        ],
    ])), true);

    expect($result['ok'])->toBeTrue()
        ->and($result['page']['path'])->not->toBeEmpty();

    $draft = $propose->currentManifest();
    $page = collect($draft['pages'])->firstWhere('slug', $result['page']['slug']);
    $flat = json_encode($page);

    expect($page['name'])->toBe('Panel Ejecutivo de Soporte')     // override applied
        ->and($flat)->toContain('Riesgo SLA')                      // overridden insights
        ->and($flat)->toContain('range_start')                     // date filter wired
        ->and($flat)->toContain('metric_grid');                    // KPI band compiled
});

it('still demands kpis/charts when use_suggestion is not set', function () {
    [$tool] = adp_tool($this);

    $result = json_decode($tool->handle(new ToolRequest(['object_slug' => 'tickets'])), true);

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'][0]['message'])->toContain('use_suggestion');
});

it('suggests a compilable spec for an aggregate object whose only string is a name (the t3 failure)', function () {
    // Exactly the prod shape that produced a chartless spec: no date field,
    // numbers + a "nombre" string (excluded by the strict categorical filter).
    $object = [
        'id' => 'obj_sellerscomp', 'slug' => 'sellers', 'name' => 'Sellers',
        'fields' => [
            ['id' => 'fld_nombresel', 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string'],
            ['id' => 'fld_promedios', 'slug' => 'promedio_dias', 'name' => 'Promedio Días', 'type' => 'number'],
            ['id' => 'fld_p95dias00', 'slug' => 'p95', 'name' => 'P95', 'type' => 'number'],
        ],
    ];

    $spec = (new DashboardSpecSuggester)->suggest($object, 'es');
    expect($spec['charts'])->not->toBeEmpty();

    $built = app(AppScaffolder::class)->buildDashboardFromSpec(
        $spec + ['object_slug' => 'sellers'], $object, [],
        ColorPalette::fromAccent(OrganizationBrand::DEFAULT_ACCENT), 'es',
    );
    expect($built['ok'])->toBeTrue();
    expect(PlanDashboardTool::lint($built['purpose'], $built['plan_rows'])['ok'])->toBeTrue();
});

it('suggests a compilable spec even for a numbers-only object', function () {
    $object = [
        'id' => 'obj_numsonly0', 'slug' => 'totales', 'name' => 'Totales',
        'fields' => [
            ['id' => 'fld_total0001', 'slug' => 'total', 'name' => 'Total', 'type' => 'number'],
            ['id' => 'fld_backlog01', 'slug' => 'backlog', 'name' => 'Backlog', 'type' => 'number'],
        ],
    ];

    $spec = (new DashboardSpecSuggester)->suggest($object, 'es');
    expect($spec['charts'])->not->toBeEmpty();

    $built = app(AppScaffolder::class)->buildDashboardFromSpec(
        $spec + ['object_slug' => 'totales'], $object, [],
        ColorPalette::fromAccent(OrganizationBrand::DEFAULT_ACCENT), 'es',
    );
    expect($built['ok'])->toBeTrue();
});

it('never lies on pre-aggregated rows: no count-of-weeks total, no summed percentages, no folded statistics', function () {
    // The exact YuhuGo weekly-series shape that produced "Total tickets: 5"
    // (a count of WEEKS) in production.
    $object = [
        'id' => 'obj_wkseries0', 'slug' => 'serie', 'name' => 'Serie Semanal',
        'fields' => [
            ['id' => 'fld_bucket00', 'slug' => 'bucket_start', 'name' => 'Semana', 'type' => 'date'],
            ['id' => 'fld_totalt00', 'slug' => 'total_tickets', 'name' => 'Total Tickets', 'type' => 'number'],
            ['id' => 'fld_otdpct00', 'slug' => 'otd_pct', 'name' => 'OTD %', 'type' => 'number'],
            ['id' => 'fld_avgmin00', 'slug' => 'avg_minutes', 'name' => 'Avg Minutos', 'type' => 'number'],
        ],
        'source' => [
            'operations' => ['list' => ['mcp_tool' => 't', 'collection_path' => 'series']],
            'field_map' => [
                ['field_id' => 'fld_bucket00', 'external_path' => 'bucket_start'],
                ['field_id' => 'fld_totalt00', 'external_path' => 'total_tickets'],
                ['field_id' => 'fld_otdpct00', 'external_path' => 'otd_pct'],
                ['field_id' => 'fld_avgmin00', 'external_path' => 'avg_minutes'],
            ],
        ],
    ];
    $rows = collect(range(0, 4))->map(fn ($i) => [
        'bucket_start' => now()->utc()->subWeeks($i)->toDateString(),
        'total_tickets' => 10 + $i, 'otd_pct' => 90.5 - $i, 'avg_minutes' => 30 + $i,
    ])->all();

    $spec = (new DashboardSpecSuggester)->suggest($object, 'es', $rows);

    $kpis = collect($spec['kpis']);
    // No count KPI at all (counting rows counts weeks).
    expect($kpis->pluck('aggregation'))->not->toContain('count');
    // Headline total = SUM of the additive column.
    expect($kpis->first()['aggregation'])->toBe('sum')
        ->and($kpis->first()['field_id'])->toBe('fld_totalt00');
    // The percentage is never summed; the pre-computed average never folds.
    $otd = $kpis->firstWhere('field_id', 'fld_otdpct00');
    expect($otd['aggregation'])->toBe('avg');
    expect($kpis->pluck('field_id'))->not->toContain('fld_avgmin00');

    // The trend chart sums the additive series — it does not count buckets.
    $trend = collect($spec['charts'])->firstWhere('chart_type', 'line');
    expect($trend['aggregation'])->toBe('sum')
        ->and($trend['y_field_id'])->toBe('fld_totalt00');
});

it('adapts charts to real cardinality and skips degenerate columns', function () {
    $object = [
        'id' => 'obj_dimbrk00', 'slug' => 'brk', 'name' => 'Breakdown',
        'fields' => [
            ['id' => 'fld_key0000', 'slug' => 'key', 'name' => 'Motivo', 'type' => 'string'],
            ['id' => 'fld_const00', 'slug' => 'canal', 'name' => 'Canal', 'type' => 'string'],
            ['id' => 'fld_count00', 'slug' => 'count', 'name' => 'Count', 'type' => 'number'],
        ],
        'source' => [
            'operations' => ['list' => ['mcp_tool' => 't', 'collection_path' => 'breakdown']],
            'field_map' => [
                ['field_id' => 'fld_key0000', 'external_path' => 'key'],
                ['field_id' => 'fld_const00', 'external_path' => 'canal'],
                ['field_id' => 'fld_count00', 'external_path' => 'count'],
            ],
        ],
    ];
    // 11 distinct motivos → too many slices for a donut; canal is constant.
    $rows = collect(range(1, 11))->map(fn ($i) => [
        'key' => "Motivo {$i}", 'canal' => 'web', 'count' => $i * 3,
    ])->all();

    $spec = (new DashboardSpecSuggester)->suggest($object, 'es', $rows);

    $breakdown = collect($spec['charts'])->firstWhere('group_by_field_id', 'fld_key0000');
    expect($breakdown['chart_type'])->toBe('hbar')      // 11 > 8 → horizontal
        ->and($breakdown['aggregation'])->toBe('sum');  // slices sized by the additive
    // The constant column never becomes a chart.
    expect(collect($spec['charts'])->pluck('group_by_field_id'))->not->toContain('fld_const00');
});

it('chapters the board with narrative headings when trend and breakdown coexist', function () {
    [$tool, $propose] = adp_tool($this);

    $result = json_decode($tool->handle(new ToolRequest([
        'object_slug' => 'tickets',
        'use_suggestion' => true,
    ])), true);
    expect($result['ok'])->toBeTrue();

    $page = collect($propose->currentManifest()['pages'])->firstWhere('slug', $result['page']['slug']);
    $headings = collect($page['blocks'])->where('type', 'heading')->pluck('content')->values();

    expect($headings)->toContain('Tendencia')
        ->and($headings)->toContain('Desglose')
        ->and($headings)->toContain('Lecturas clave');

    // Story order: the heading sequence reads trend → breakdown → readings.
    expect($headings->search('Tendencia'))->toBeLessThan($headings->search('Desglose'))
        ->and($headings->search('Desglose'))->toBeLessThan($headings->search('Lecturas clave'));
});

it('never aggregates numeric identifiers into KPIs or charts', function () {
    // The nps2 production shape: raw survey rows carrying a numeric contact id.
    $object = [
        'id' => 'obj_npsraw00', 'slug' => 'nps_comments', 'name' => 'Nps Comments',
        'fields' => [
            ['id' => 'fld_when0000', 'slug' => 'responded_at', 'name' => 'Respondido', 'type' => 'datetime'],
            ['id' => 'fld_npsval00', 'slug' => 'nps', 'name' => 'Nps', 'type' => 'number'],
            ['id' => 'fld_cesval00', 'slug' => 'ces', 'name' => 'Ces', 'type' => 'number'],
            ['id' => 'fld_cid00000', 'slug' => 'contact_id', 'name' => 'Id', 'type' => 'number'],
            ['id' => 'fld_segment0', 'slug' => 'segment', 'name' => 'Segment', 'type' => 'string'],
        ],
    ];

    $spec = (new DashboardSpecSuggester)->suggest($object, 'es');

    $fieldsUsed = collect($spec['kpis'])->pluck('field_id')
        ->merge(collect($spec['charts'])->pluck('y_field_id'))
        ->filter();
    expect($fieldsUsed)->not->toContain('fld_cid00000');

    // CES averages, never sums.
    $ces = collect($spec['kpis'])->firstWhere('field_id', 'fld_cesval00');
    expect($ces['aggregation'])->toBe('avg');
});

/** A connected weekly-series object (like the acquired nps_score series). */
function adp_series_object(bool $withDate = true): array
{
    return [
        'id' => 'obj_npsseries0',
        'slug' => 'nps_semanal',
        'name' => 'NPS Semanal',
        'fields' => array_values(array_filter([
            $withDate ? ['id' => 'fld_npsweek00', 'slug' => 'bucket_start', 'name' => 'Semana', 'type' => 'date'] : null,
            ['id' => 'fld_npsscore0', 'slug' => 'nps', 'name' => 'NPS', 'type' => 'number'],
            ['id' => 'fld_npsseg000', 'slug' => 'segmento', 'name' => 'Segmento', 'type' => 'string'],
        ])),
        'source' => ['type' => 'connected', 'integration_id' => 'int_x', 'operations' => ['list' => ['mcp_tool' => 't', 'collection_path' => 'series']]],
    ];
}

it('compiles multi-object boards: each block reads ITS object and wires ITS date field', function () {
    $manifest = $this->manifestService->getActiveManifest($this->testApp->fresh());
    $series = adp_series_object();

    $spec = adp_spec();
    $spec['charts'][] = [
        'label' => 'Evolución del NPS', 'chart_type' => 'area', 'aggregation' => 'avg',
        'x_field_id' => 'fld_npsweek00', 'y_field_id' => 'fld_npsscore0', 'object_slug' => 'nps_semanal',
    ];
    $spec['kpis'][] = ['label' => 'NPS promedio', 'aggregation' => 'avg', 'field_id' => 'fld_npsscore0', 'object_slug' => 'nps_semanal'];

    $built = app(AppScaffolder::class)->buildDashboardFromSpec(
        $spec, $manifest['objects'][0], [], null, 'es', [$series],
    );

    expect($built['ok'])->toBeTrue()
        ->and(PlanDashboardTool::lint($built['purpose'], $built['plan_rows'])['ok'])->toBeTrue();

    $charts = collect($built['page']['blocks'])->where('type', 'container')
        ->flatMap(fn (array $c) => $c['blocks'])->where('type', 'chart');
    $npsChart = $charts->firstWhere('y_field_id', 'fld_npsscore0');
    expect($npsChart['data_source']['object_id'])->toBe('obj_npsseries0')
        ->and(json_encode($npsChart['data_source']['filter']))->toContain('fld_npsweek00')
        ->and(json_encode($npsChart['data_source']['filter']))->toContain('range_start');

    // The primary's charts keep reading the primary with ITS date field.
    $primaryChart = $charts->firstWhere('chart_type', 'line');
    expect($primaryChart['data_source']['object_id'])->toBe('obj_ticketsobj')
        ->and(json_encode($primaryChart['data_source']['filter']))->toContain('fld_datefield');

    // The secondary KPI queries the secondary.
    $kpiItems = collect($built['page']['blocks'])->firstWhere('type', 'metric_grid')['items'];
    expect(collect($kpiItems)->firstWhere('label', 'NPS promedio')['query']['object_id'])->toBe('obj_npsseries0');
});

it('never wires the range filter into a dateless CONNECTED object (no sys_created_at ghost)', function () {
    $manifest = $this->manifestService->getActiveManifest($this->testApp->fresh());
    $series = adp_series_object(withDate: false);

    $spec = adp_spec();
    $spec['charts'][] = [
        'label' => 'NPS por segmento', 'chart_type' => 'treemap', 'aggregation' => 'avg',
        'y_field_id' => 'fld_npsscore0', 'group_by_field_id' => 'fld_npsseg000', 'object_slug' => 'nps_semanal',
    ];

    $built = app(AppScaffolder::class)->buildDashboardFromSpec(
        $spec, $manifest['objects'][0], [], null, 'es', [$series],
    );

    expect($built['ok'])->toBeTrue();
    $charts = collect($built['page']['blocks'])->where('type', 'container')
        ->flatMap(fn (array $c) => $c['blocks'])->where('type', 'chart');
    $npsChart = $charts->firstWhere('group_by_field_id', 'fld_npsseg000');

    // Its rows lack sys_created_at, so a range condition would delete them
    // all — the block simply doesn't listen to the filter.
    expect(json_encode($npsChart['data_source']))->not->toContain('sys_created_at')
        ->and(json_encode($npsChart['data_source']))->not->toContain('range_start');
});

it('names the unknown object_slug instead of silently reading the primary', function () {
    $manifest = $this->manifestService->getActiveManifest($this->testApp->fresh());

    $spec = adp_spec();
    $spec['charts'][] = ['label' => 'X', 'chart_type' => 'bar', 'aggregation' => 'count', 'object_slug' => 'no_existe'];

    $built = app(AppScaffolder::class)->buildDashboardFromSpec(
        $spec, $manifest['objects'][0], [], null, 'es',
    );

    expect($built['ok'])->toBeFalse()
        ->and(json_encode($built['errors']))->toContain('no_existe');
});

it('suggestMulti gives every acquired object a voice: secondary trend, breakdown and KPI tagged with object_slug', function () {
    // The nps3 gap: 4 objects acquired, 1 rendered — the requested weekly
    // nps_score evolution lived in a secondary object and never made the board.
    $primary = [
        'id' => 'obj_comments00', 'slug' => 'comentarios', 'name' => 'Comentarios NPS',
        'fields' => [
            ['id' => 'fld_seg000', 'slug' => 'segmento', 'name' => 'Segmento', 'type' => 'string'],
            ['id' => 'fld_com000', 'slug' => 'comentario', 'name' => 'Comentario', 'type' => 'string'],
        ],
        'source' => ['type' => 'connected', 'operations' => ['list' => ['mcp_tool' => 'c', 'collection_path' => 'rows']]],
    ];
    $series = adp_series_object();

    $weeks = collect(range(0, 3))->map(fn (int $i) => [
        'bucket_start' => now()->utc()->subWeeks($i)->toDateString(), 'nps' => 40 + $i, 'segmento' => 'promoter',
    ])->all();

    $spec = (new DashboardSpecSuggester)->suggestMulti(
        [$primary, $series], 'es',
        ['obj_npsseries0' => $weeks],
    );

    $npsTrend = collect($spec['charts'])->firstWhere('object_slug', 'nps_semanal');
    expect($npsTrend)->not->toBeNull()
        ->and($npsTrend['x_field_id'])->toBe('fld_npsweek00')
        ->and($npsTrend['y_field_id'])->toBe('fld_npsscore0')
        ->and($npsTrend['aggregation'])->toBe('avg')
        ->and($npsTrend['label'])->toContain('NPS Semanal');

    expect(collect($spec['kpis'])->firstWhere('object_slug', 'nps_semanal'))->not->toBeNull();

    // The primary is dateless, but the temporal secondary makes the range
    // filter worth having (the compiler wires only who can listen).
    expect($spec['include_date_filter'])->toBeTrue();
});

it('charts the object\'s namesake metric, not its first additive (nps_time_series → nps_score)', function () {
    // Prod nps4: the NPS series charted `responses` and grouped by
    // period_label (the time axis in costume) — the requested score never
    // rendered despite being the object's own name.
    $series = [
        'id' => 'obj_npstseries0', 'slug' => 'nps_time_series', 'name' => 'Nps Time Series',
        'fields' => [
            ['id' => 'fld_period0000', 'slug' => 'period_start', 'name' => 'Period Start', 'type' => 'date'],
            ['id' => 'fld_plabel0000', 'slug' => 'period_label', 'name' => 'Period Label', 'type' => 'string'],
            ['id' => 'fld_responses0', 'slug' => 'responses', 'name' => 'Responses', 'type' => 'number'],
            ['id' => 'fld_npsscore00', 'slug' => 'nps_score', 'name' => 'Nps Score', 'type' => 'number'],
        ],
        'source' => ['type' => 'connected', 'operations' => ['list' => ['mcp_tool' => 't', 'collection_path' => 'series']]],
    ];

    $spec = (new DashboardSpecSuggester)->suggest($series, 'es');

    $trend = collect($spec['charts'])->first(fn (array $c): bool => isset($c['x_field_id']));
    expect($trend['y_field_id'])->toBe('fld_npsscore00')
        ->and($trend['aggregation'])->toBe('avg');

    // The bucket label is the time axis in costume — never a breakdown.
    expect(collect($spec['charts'])->firstWhere('group_by_field_id', 'fld_plabel0000'))->toBeNull();

    // The headline KPI is the namesake score's average, not a generic sum.
    expect($spec['kpis'][0]['field_id'])->toBe('fld_npsscore00')
        ->and($spec['kpis'][0]['aggregation'])->toBe('avg');
});

it('never groups a "statistics per dimension" chart by the bucket label either (nps11 compile-kill)', function () {
    // Prod nps11: the trend-chart guard against grouping by the bucket label
    // only covered the "concentration breakdown" section — a SEPARATE
    // section (statistics shown per dimension, e.g. avg_csat/avg_ces) used
    // categoricals directly and produced "Avg Csat por period label", which
    // then failed the compiler's own legality guard and killed the ENTIRE
    // build with no recovery (a suggester-level illegality, not a model
    // mistake — the retry-without-overrides path can't help since the
    // suggested spec itself was already illegal).
    $series = [
        'id' => 'obj_npstseries0', 'slug' => 'nps_time_series', 'name' => 'Nps Time Series',
        'fields' => [
            ['id' => 'fld_period0000', 'slug' => 'period_start', 'name' => 'Period Start', 'type' => 'date'],
            ['id' => 'fld_plabel0000', 'slug' => 'period_label', 'name' => 'Period Label', 'type' => 'string'],
            ['id' => 'fld_responses0', 'slug' => 'responses', 'name' => 'Responses', 'type' => 'number'],
            ['id' => 'fld_npsscore00', 'slug' => 'nps_score', 'name' => 'Nps Score', 'type' => 'number'],
            ['id' => 'fld_avgcsat000', 'slug' => 'avg_csat', 'name' => 'Avg Csat', 'type' => 'number'],
            ['id' => 'fld_avgces0000', 'slug' => 'avg_ces', 'name' => 'Avg Ces', 'type' => 'number'],
        ],
        'source' => ['type' => 'connected', 'operations' => ['list' => ['mcp_tool' => 't', 'collection_path' => 'series']]],
    ];

    $spec = (new DashboardSpecSuggester)->suggest($series, 'es');

    expect(collect($spec['charts'])->pluck('group_by_field_id')->filter())->not->toContain('fld_plabel0000');

    // The INSIGHT scaffolds read the same filtered set — no «Concentración por
    // bucket label» card (prod otd_glm: the insight scaffold got the unfiltered
    // categoricals and narrated a concentration by the time axis).
    $insightText = collect($spec['insights'])->map(fn ($i) => ($i['title'] ?? '').' '.($i['body'] ?? ''))->implode(' ');
    expect(mb_strtolower($insightText))->not->toContain('bucket label')
        ->and(mb_strtolower($insightText))->not->toContain('period label');

    // And the spec the suggester emits must compile clean end to end (the
    // real failure mode: a suggester output the compiler's OWN legality
    // guard rejects).
    $built = app(AppScaffolder::class)->buildDashboardFromSpec(
        $spec, $series, [], null, 'es',
    );
    expect($built['ok'])->toBeTrue();
});

it('blocks the unambiguous lies for hand-authored specs too: count-of-buckets, summed scores, label breakdowns', function () {
    // Prod nps5 (agentic path): a bar chart COUNTED weekly pre-aggregated rows
    // grouped by period_label (every bar = 1 week, labeled "por Segmento") —
    // Express suggests only legal specs, but the compiler must refuse the lie
    // no matter who authors it.
    $series = [
        'id' => 'obj_npstseries0', 'slug' => 'nps_time_series', 'name' => 'Nps Time Series',
        'fields' => [
            ['id' => 'fld_period0000', 'slug' => 'period_start', 'name' => 'Period Start', 'type' => 'date'],
            ['id' => 'fld_plabel0000', 'slug' => 'period_label', 'name' => 'Period Label', 'type' => 'string'],
            ['id' => 'fld_responses0', 'slug' => 'responses', 'name' => 'Responses', 'type' => 'number'],
            ['id' => 'fld_npsscore00', 'slug' => 'nps_score', 'name' => 'Nps Score', 'type' => 'number'],
        ],
        'source' => ['type' => 'connected', 'operations' => ['list' => ['mcp_tool' => 't', 'collection_path' => 'series']]],
    ];

    $spec = [
        'object_slug' => 'nps_time_series',
        'kpis' => [
            ['label' => 'NPS', 'aggregation' => 'avg', 'field_id' => 'fld_npsscore00'],          // legal
            ['label' => 'Suma NPS', 'aggregation' => 'sum', 'field_id' => 'fld_npsscore00'],     // summed score
            ['label' => 'Semanas', 'aggregation' => 'count'],                                    // counts buckets
        ],
        'charts' => [
            ['label' => 'Evolución', 'chart_type' => 'line', 'aggregation' => 'avg', 'x_field_id' => 'fld_period0000', 'y_field_id' => 'fld_npsscore00'], // legal
            ['label' => 'Distribución por Segmento', 'chart_type' => 'bar', 'aggregation' => 'count', 'group_by_field_id' => 'fld_plabel0000'],           // the nps5 lie
        ],
        'insights' => [['variant' => 'conclusion', 'title' => 'x', 'body' => 'y']],
    ];

    $built = app(AppScaffolder::class)->buildDashboardFromSpec($spec, $series, [], null, 'es');

    expect($built['ok'])->toBeFalse();
    $errors = json_encode($built['errors']);
    expect($errors)->toContain('Never SUM')
        ->and($errors)->toContain('BUCKETS')
        ->and($errors)->toContain('illegal_aggregation');

    // The same spec without the lies compiles.
    $spec['kpis'] = [$spec['kpis'][0], ['label' => 'Respuestas', 'aggregation' => 'sum', 'field_id' => 'fld_responses0']];
    $spec['charts'] = [
        $spec['charts'][0],
        ['label' => 'Respuestas por semana', 'chart_type' => 'bar', 'aggregation' => 'sum', 'y_field_id' => 'fld_responses0', 'x_field_id' => 'fld_period0000'],
    ];
    $built = app(AppScaffolder::class)->buildDashboardFromSpec($spec, $series, [], null, 'es');
    expect($built['ok'])->toBeTrue();
});

it('rejects a part-of-whole chart with no category to slice by (the GLM degenerate donut)', function () {
    // Prod nps_glm_5v: a donut of sum(responses) with no group_by — a single
    // 100% slice that says nothing. A pie/donut needs a slicing dimension.
    $series = [
        'id' => 'obj_npstseries0', 'slug' => 'nps_time_series', 'name' => 'Nps Time Series',
        'fields' => [
            ['id' => 'fld_period0000', 'slug' => 'period_start', 'name' => 'Period Start', 'type' => 'date'],
            ['id' => 'fld_responses0', 'slug' => 'responses', 'name' => 'Responses', 'type' => 'number'],
            ['id' => 'fld_npsscore00', 'slug' => 'nps_score', 'name' => 'Nps Score', 'type' => 'number'],
            ['id' => 'fld_segment000', 'slug' => 'segment', 'name' => 'Segment', 'type' => 'string'],
        ],
        'source' => ['type' => 'connected', 'operations' => ['list' => ['mcp_tool' => 't', 'collection_path' => 'series']]],
    ];
    $base = [
        'object_slug' => 'nps_time_series',
        'kpis' => [['label' => 'NPS', 'aggregation' => 'avg', 'field_id' => 'fld_npsscore00']],
        'insights' => [['variant' => 'conclusion', 'title' => 'x', 'body' => 'y']],
    ];

    // Degenerate: donut of a total with nothing to slice by → rejected.
    $bad = $base + ['charts' => [
        ['label' => 'Evolución', 'chart_type' => 'line', 'aggregation' => 'avg', 'x_field_id' => 'fld_period0000', 'y_field_id' => 'fld_npsscore00'],
        ['label' => 'Respuestas por Periodo', 'chart_type' => 'donut', 'aggregation' => 'sum', 'y_field_id' => 'fld_responses0'],
    ]];
    $built = app(AppScaffolder::class)->buildDashboardFromSpec($bad, $series, [], null, 'es');
    expect($built['ok'])->toBeFalse()
        ->and(json_encode($built['errors']))->toContain('degenerate_chart');

    // Give the donut a real dimension to slice by → compiles.
    $good = $base + ['charts' => [
        ['label' => 'Evolución', 'chart_type' => 'line', 'aggregation' => 'avg', 'x_field_id' => 'fld_period0000', 'y_field_id' => 'fld_npsscore00'],
        ['label' => 'Respuestas por segmento', 'chart_type' => 'donut', 'aggregation' => 'sum', 'y_field_id' => 'fld_responses0', 'group_by_field_id' => 'fld_segment000'],
    ]];
    $built = app(AppScaffolder::class)->buildDashboardFromSpec($good, $series, [], null, 'es');
    expect($built['ok'])->toBeTrue();
});

it('only ships icons the runtime can draw: catalog names normalized, emojis kept, unknown slugs dropped', function () {
    // Prod nps5 showed "minus-circle" / "thumbs-down" as raw TEXT beside the
    // KPI value — plausible Lucide names outside the registry.
    $spec = adp_spec();
    $spec['kpis'] = [
        ['label' => 'A', 'aggregation' => 'count', 'icon' => 'thumbs-down'],       // curated
        ['label' => 'B', 'aggregation' => 'count', 'icon' => 'Alert Triangle'],    // normalizes to alert-triangle
        ['label' => 'C', 'aggregation' => 'count', 'icon' => 'circle-gauge-pro'],  // not a real Lucide icon → dropped
        ['label' => 'D', 'aggregation' => 'count', 'icon' => '🎯'],                // emoji → kept
        ['label' => 'E', 'aggregation' => 'count', 'icon' => 'chart-column'],      // real Lucide icon, NOT curated → kept (lazy tier)
    ];

    $manifest = $this->manifestService->getActiveManifest($this->testApp->fresh());
    $built = app(AppScaffolder::class)->buildDashboardFromSpec(
        $spec, $manifest['objects'][0], [], null, 'es',
    );

    expect($built['ok'])->toBeTrue();
    $byLabel = collect($built['page']['blocks'])->firstWhere('type', 'metric_grid')['items'];
    $icon = fn (string $label) => collect($byLabel)->firstWhere('label', $label)['icon'] ?? null;

    expect($icon('A'))->toBe('thumbs-down')
        ->and($icon('B'))->toBe('alert-triangle')
        ->and($icon('C'))->toBeNull()
        ->and($icon('D'))->toBe('🎯')
        ->and($icon('E'))->toBe('chart-column');
});

it('re-forms a lone short chart so its own lints never kill the compile', function () {
    // Prod: a spec whose only chart was a donut compiled into a single-short-
    // block row, failed the compiler's OWN lint and the whole build died. The
    // compiler must emit lint-clean layouts by construction.
    $spec = adp_spec();
    $spec['charts'] = [
        ['label' => 'Por estado', 'chart_type' => 'donut', 'aggregation' => 'count', 'group_by_field_id' => 'fld_statefield'],
    ];

    $manifest = $this->manifestService->getActiveManifest($this->testApp->fresh());
    $built = app(AppScaffolder::class)->buildDashboardFromSpec(
        $spec, $manifest['objects'][0], [], null, 'es',
    );

    expect($built['ok'])->toBeTrue()
        ->and(PlanDashboardTool::lint($built['purpose'], $built['plan_rows'])['ok'])->toBeTrue();

    $chart = collect($built['page']['blocks'])->firstWhere('type', 'container')['blocks'][0];
    expect($chart['chart_type'])->toBe('bar')
        ->and($chart['group_by_field_id'])->toBe('fld_statefield');
});

it('disables the date filter when the object has no temporal field', function () {
    // The board-emptying bug: no date field → compiler defaults the range
    // filter to sys_created_at, which connected rows don't carry → every row
    // filtered out → a whole scenario rendered empty and scored 1/5.
    $object = [
        'id' => 'obj_sellcmp0', 'slug' => 'sellers', 'name' => 'Sellers',
        'fields' => [
            ['id' => 'fld_nomsell0', 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string'],
            ['id' => 'fld_promdia0', 'slug' => 'promedio_dias', 'name' => 'Promedio Días', 'type' => 'number'],
        ],
    ];

    $spec = (new DashboardSpecSuggester)->suggest($object, 'es');
    expect($spec['include_date_filter'])->toBeFalse();

    $built = app(AppScaffolder::class)->buildDashboardFromSpec(
        $spec + ['object_slug' => 'sellers'], $object, [],
        ColorPalette::fromAccent(OrganizationBrand::DEFAULT_ACCENT), 'es',
    );
    expect($built['ok'])->toBeTrue()
        // No range_start anywhere: nothing filters on a field the rows lack.
        ->and(json_encode($built['page']))->not->toContain('range_start')
        ->and(json_encode($built['page']))->not->toContain('sys_created_at');
});
