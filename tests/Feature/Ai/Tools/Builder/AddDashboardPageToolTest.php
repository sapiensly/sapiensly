<?php

use App\Ai\Tools\Builder\AddDashboardPageTool;
use App\Ai\Tools\Builder\ProposeChangeTool;
use App\Models\App;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\AppScaffolder;
use App\Services\Manifest\ManifestValidator;
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
