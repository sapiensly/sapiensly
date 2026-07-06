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
