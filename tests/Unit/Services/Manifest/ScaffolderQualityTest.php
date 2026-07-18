<?php

use App\Services\Manifest\AppScaffolder;
use App\Services\Manifest\ManifestValidator;

/**
 * @return array<string, mixed>
 */
function scaffoldFor(string $locale): array
{
    $base = [
        'schema_version' => '1.0.0',
        'id' => 'app_scaffold_q1',
        'slug' => 'pos',
        'name' => 'POS',
        'version' => 1,
        'objects' => [],
        'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
        'settings' => ['default_locale' => $locale, 'default_currency' => 'MXN'],
    ];

    $spec = [
        'objects' => [[
            'name' => 'Comandas',
            'slug' => 'comandas',
            'fields' => [
                ['name' => 'Folio', 'slug' => 'folio', 'type' => 'string', 'options' => null],
                ['name' => 'Estado', 'slug' => 'estado', 'type' => 'single_select', 'options' => [
                    ['value' => 'abierta', 'label' => 'Abierta'],
                    ['value' => 'pagada', 'label' => 'Pagada'],
                ]],
                ['name' => 'Total', 'slug' => 'total', 'type' => 'currency', 'options' => null],
            ],
        ]],
        'links' => [],
    ];

    return app(AppScaffolder::class)->assemble($base, $spec);
}

/**
 * A parent (comandas) with a child (renglones) so the scaffold builds a
 * master-detail page. Link: a renglón belongs to one comanda.
 *
 * @return array<string, mixed>
 */
function scaffoldWithChild(string $locale): array
{
    $base = [
        'schema_version' => '1.0.0',
        'id' => 'app_scaffold_q2',
        'slug' => 'pos2',
        'name' => 'POS',
        'version' => 1,
        'objects' => [],
        'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
        'settings' => ['default_locale' => $locale, 'default_currency' => 'MXN'],
    ];

    $spec = [
        'objects' => [
            ['name' => 'Comandas', 'slug' => 'comandas', 'fields' => [
                ['name' => 'Folio', 'slug' => 'folio', 'type' => 'string', 'options' => null],
            ]],
            ['name' => 'Renglones', 'slug' => 'renglones', 'fields' => [
                ['name' => 'Concepto', 'slug' => 'concepto', 'type' => 'string', 'options' => null],
                ['name' => 'Cantidad', 'slug' => 'cantidad', 'type' => 'number', 'options' => null],
                ['name' => 'Subtotal', 'slug' => 'subtotal', 'type' => 'currency', 'options' => null],
            ]],
        ],
        'links' => [['from' => 'renglones', 'to' => 'comandas', 'name' => 'comanda']],
    ];

    return app(AppScaffolder::class)->assemble($base, $spec);
}

/**
 * A POS-shaped model: an order (comandas) ← line (renglones) → priced product
 * (platillos). The scaffolder should detect this and generate a POS screen.
 *
 * @return array<string, mixed>
 */
function scaffoldPos(string $locale): array
{
    $base = [
        'schema_version' => '1.0.0',
        'id' => 'app_scaffold_p1',
        'slug' => 'pos3',
        'name' => 'POS',
        'version' => 1,
        'objects' => [],
        'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
        'settings' => ['default_locale' => $locale, 'default_currency' => 'MXN'],
    ];

    $spec = [
        'objects' => [
            ['name' => 'Comandas', 'slug' => 'comandas', 'fields' => [
                ['name' => 'Folio', 'slug' => 'folio', 'type' => 'string', 'options' => null],
                ['name' => 'Estado', 'slug' => 'estado', 'type' => 'single_select', 'options' => [
                    ['value' => 'abierta', 'label' => 'Abierta'],
                    ['value' => 'pagada', 'label' => 'Pagada'],
                ]],
            ]],
            ['name' => 'Platillos', 'slug' => 'platillos', 'fields' => [
                ['name' => 'Nombre', 'slug' => 'nombre', 'type' => 'string', 'options' => null],
                ['name' => 'Precio', 'slug' => 'precio', 'type' => 'currency', 'options' => null],
                ['name' => 'Imagen', 'slug' => 'url_imagen', 'type' => 'string', 'options' => null],
            ]],
            ['name' => 'Renglones', 'slug' => 'renglones', 'fields' => [
                ['name' => 'Cantidad', 'slug' => 'cantidad', 'type' => 'number', 'options' => null],
                // The model often emits a manual subtotal — the recipe should
                // REUSE it (convert to the computed formula), not duplicate it.
                ['name' => 'Subtotal', 'slug' => 'subtotal', 'type' => 'currency', 'options' => null],
            ]],
        ],
        'links' => [
            ['from' => 'renglones', 'to' => 'comandas', 'name' => 'comanda'],
            ['from' => 'renglones', 'to' => 'platillos', 'name' => 'platillo'],
        ],
    ];

    return app(AppScaffolder::class)->assemble($base, $spec);
}

/**
 * A product-development model with the POS SHAPE but no commerce: a milestone
 * (hitos) that belongs to two BUDGET-priced parents (proyectos, productos). The
 * structural triad matches, but a budget is not a sale price — the scaffolder
 * must NOT generate a "Punto de venta" screen here.
 *
 * @return array<string, mixed>
 */
function scaffoldNpd(): array
{
    $base = [
        'schema_version' => '1.0.0', 'id' => 'app_scaffold_npd', 'slug' => 'npd', 'name' => 'NPD', 'version' => 1,
        'objects' => [], 'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
        'settings' => ['default_locale' => 'es-MX', 'default_currency' => 'MXN'],
    ];
    $spec = [
        'objects' => [
            ['name' => 'Productos', 'slug' => 'productos', 'fields' => [
                ['name' => 'Nombre', 'slug' => 'nombre', 'type' => 'string', 'options' => null],
                ['name' => 'Presupuesto', 'slug' => 'presupuesto', 'type' => 'currency', 'options' => null],
            ]],
            ['name' => 'Proyectos', 'slug' => 'proyectos', 'fields' => [
                ['name' => 'Nombre', 'slug' => 'nombre', 'type' => 'string', 'options' => null],
                ['name' => 'Estado', 'slug' => 'estado', 'type' => 'single_select', 'options' => [
                    ['value' => 'activo', 'label' => 'Activo'], ['value' => 'cerrado', 'label' => 'Cerrado'],
                ]],
                ['name' => 'Presupuesto', 'slug' => 'presupuesto', 'type' => 'currency', 'options' => null],
            ]],
            ['name' => 'Hitos', 'slug' => 'hitos', 'fields' => [
                ['name' => 'Nombre', 'slug' => 'nombre', 'type' => 'string', 'options' => null],
                ['name' => 'Fecha', 'slug' => 'fecha', 'type' => 'date', 'options' => null],
            ]],
        ],
        'links' => [
            ['from' => 'hitos', 'to' => 'proyectos', 'name' => 'proyecto'],
            ['from' => 'hitos', 'to' => 'productos', 'name' => 'producto'],
        ],
    ];

    return app(AppScaffolder::class)->assemble($base, $spec);
}

/**
 * Two genuine commerce triads (comandas←renglones→platillos AND
 * pedidos←items→articulos). The scaffolder must dedup to a SINGLE POS screen.
 *
 * @return array<string, mixed>
 */
function scaffoldTwoPosTriads(): array
{
    $base = [
        'schema_version' => '1.0.0', 'id' => 'app_scaffold_2pos', 'slug' => 'pos2x', 'name' => 'POS', 'version' => 1,
        'objects' => [], 'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
        'settings' => ['default_locale' => 'es-MX', 'default_currency' => 'MXN'],
    ];
    $spec = [
        'objects' => [
            ['name' => 'Comandas', 'slug' => 'comandas', 'fields' => [
                ['name' => 'Folio', 'slug' => 'folio', 'type' => 'string', 'options' => null],
                ['name' => 'Estado', 'slug' => 'estado', 'type' => 'single_select', 'options' => [['value' => 'abierta', 'label' => 'Abierta']]],
            ]],
            ['name' => 'Platillos', 'slug' => 'platillos', 'fields' => [
                ['name' => 'Nombre', 'slug' => 'nombre', 'type' => 'string', 'options' => null],
                ['name' => 'Precio', 'slug' => 'precio', 'type' => 'currency', 'options' => null],
            ]],
            ['name' => 'Renglones', 'slug' => 'renglones', 'fields' => [
                ['name' => 'Cantidad', 'slug' => 'cantidad', 'type' => 'number', 'options' => null],
            ]],
            ['name' => 'Pedidos', 'slug' => 'pedidos', 'fields' => [
                ['name' => 'Numero', 'slug' => 'numero', 'type' => 'string', 'options' => null],
                ['name' => 'Estatus', 'slug' => 'estatus', 'type' => 'single_select', 'options' => [['value' => 'nuevo', 'label' => 'Nuevo']]],
            ]],
            ['name' => 'Articulos', 'slug' => 'articulos', 'fields' => [
                ['name' => 'Titulo', 'slug' => 'titulo', 'type' => 'string', 'options' => null],
                ['name' => 'Precio', 'slug' => 'precio', 'type' => 'currency', 'options' => null],
            ]],
            ['name' => 'Items', 'slug' => 'items', 'fields' => [
                ['name' => 'Cantidad', 'slug' => 'cantidad', 'type' => 'number', 'options' => null],
            ]],
        ],
        'links' => [
            ['from' => 'renglones', 'to' => 'comandas', 'name' => 'comanda'],
            ['from' => 'renglones', 'to' => 'platillos', 'name' => 'platillo'],
            ['from' => 'items', 'to' => 'pedidos', 'name' => 'pedido'],
            ['from' => 'items', 'to' => 'articulos', 'name' => 'articulo'],
        ],
    ];

    return app(AppScaffolder::class)->assemble($base, $spec);
}

function pageBySlug(array $manifest, string $slug): ?array
{
    return collect($manifest['pages'])->firstWhere('slug', $slug);
}

/** Count the POS screens in a manifest (a POS page carries a split_view block). */
function posPageCount(array $manifest): int
{
    return collect($manifest['pages'])
        ->filter(fn ($p) => collect($p['blocks'] ?? [])->contains(fn ($b) => ($b['type'] ?? null) === 'split_view'))
        ->count();
}

function blocksByType(array $page, string $type): array
{
    return collect($page['blocks'])->where('type', $type)->values()->all();
}

function blockByType(array $page, string $type): ?array
{
    return collect($page['blocks'])->firstWhere('type', $type);
}

/**
 * A work-plan model: a Tasks object with a start date, an end date and a status
 * — the shape a project/plan tracker takes, which should render a Gantt.
 *
 * @return array<string, mixed>
 */
function scaffoldPlan(string $locale): array
{
    $base = [
        'schema_version' => '1.0.0',
        'id' => 'app_scaffold_pl1',
        'slug' => 'plan',
        'name' => 'Plan',
        'version' => 1,
        'objects' => [],
        'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
        'settings' => ['default_locale' => $locale, 'default_currency' => 'USD'],
    ];

    $spec = [
        'objects' => [[
            'name' => 'Tasks',
            'slug' => 'tasks',
            'fields' => [
                ['name' => 'Task', 'slug' => 'task', 'type' => 'string', 'options' => null],
                ['name' => 'Start', 'slug' => 'start_date', 'type' => 'date', 'options' => null],
                ['name' => 'End', 'slug' => 'end_date', 'type' => 'date', 'options' => null],
                ['name' => 'Status', 'slug' => 'status', 'type' => 'single_select', 'options' => [
                    ['value' => 'todo', 'label' => 'To do'],
                    ['value' => 'done', 'label' => 'Done'],
                ]],
            ],
        ]],
        'links' => [],
    ];

    return app(AppScaffolder::class)->assemble($base, $spec);
}

it('renders a plan object with two date fields as a Gantt, coloured by status', function () {
    $manifest = scaffoldPlan('en');

    $page = pageBySlug($manifest, 'tasks');
    $gantt = blockByType($page, 'gantt');
    expect($gantt)->not->toBeNull();

    $fields = collect($manifest['objects'][0]['fields']);
    $start = $fields->firstWhere('slug', 'start_date')['id'];
    $end = $fields->firstWhere('slug', 'end_date')['id'];
    $title = $fields->firstWhere('slug', 'task')['id'];
    $status = $fields->firstWhere('slug', 'status')['id'];

    expect($gantt['start_field_id'])->toBe($start)
        ->and($gantt['end_field_id'])->toBe($end)
        ->and($gantt['title_field_id'])->toBe($title)
        ->and($gantt['color_field_id'])->toBe($status);

    // The whole manifest still validates.
    expect(app(ManifestValidator::class)->validate($manifest)->valid)->toBeTrue();
});

it('does not add a Gantt when an object has fewer than two date fields', function () {
    // Comandas has a status but only a folio + total — no date pair.
    $manifest = scaffoldFor('es-MX');
    expect(blockByType(pageBySlug($manifest, 'comandas'), 'gantt'))->toBeNull();
});

/** A single-date EVENT object (a shoot day, an appointment) — the calendar case. */
function scaffoldEvent(): array
{
    $base = [
        'schema_version' => '1.0.0', 'id' => 'app_scaffold_ev1', 'slug' => 'agenda', 'name' => 'Agenda', 'version' => 1,
        'objects' => [], 'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
        'settings' => ['default_locale' => 'es-MX', 'default_currency' => 'MXN'],
    ];
    $spec = [
        'objects' => [[
            'name' => 'Citas', 'slug' => 'citas', 'fields' => [
                ['name' => 'Titulo', 'slug' => 'titulo', 'type' => 'string', 'options' => null],
                ['name' => 'Fecha', 'slug' => 'fecha', 'type' => 'date', 'options' => null],
                ['name' => 'Estado', 'slug' => 'estado', 'type' => 'single_select', 'options' => [
                    ['value' => 'programada', 'label' => 'Programada'], ['value' => 'realizada', 'label' => 'Realizada'],
                ]],
            ],
        ]],
        'links' => [],
    ];

    return app(AppScaffolder::class)->assemble($base, $spec);
}

it('renders a single-date event object as a calendar, coloured by status', function () {
    $manifest = scaffoldEvent();
    $page = pageBySlug($manifest, 'citas');
    $calendar = blockByType($page, 'calendar');
    expect($calendar)->not->toBeNull();

    $fields = collect($manifest['objects'][0]['fields']);
    expect($calendar['date_field_id'])->toBe($fields->firstWhere('slug', 'fecha')['id'])
        ->and($calendar['title_field_id'])->toBe($fields->firstWhere('slug', 'titulo')['id'])
        ->and($calendar['color_field_id'])->toBe($fields->firstWhere('slug', 'estado')['id']);

    // A lone date is an event, not a span — no Gantt.
    expect(blockByType($page, 'gantt'))->toBeNull();
    expect(app(ManifestValidator::class)->validate($manifest)->valid)->toBeTrue();
});

it('prefers a Gantt over a calendar when an object spans two dates', function () {
    $manifest = scaffoldPlan('en');
    $page = pageBySlug($manifest, 'tasks');
    expect(blockByType($page, 'gantt'))->not->toBeNull()
        ->and(blockByType($page, 'calendar'))->toBeNull();
});

it('scaffolds an editable kanban with colour-coded status options', function () {
    $manifest = scaffoldFor('es-MX');

    $page = pageBySlug($manifest, 'comandas');
    $kanban = blockByType($page, 'kanban');
    expect($kanban)->not->toBeNull();
    expect($kanban['editable'])->toBeTrue();

    $estado = collect($manifest['objects'][0]['fields'])->firstWhere('slug', 'estado');
    foreach ($estado['options'] as $opt) {
        expect($opt)->toHaveKey('color');
        expect($opt['color'])->toMatch('/^#[0-9a-f]{6}$/i');
    }
});

it('localises the generated chrome for a Spanish app', function () {
    $manifest = scaffoldFor('es-MX');
    $page = pageBySlug($manifest, 'comandas');

    expect(blockByType($page, 'button')['label'])->toBe('Agregar Comanda');

    $form = blockByType($page, 'modal')['blocks'][0];
    expect($form['submit_label'])->toBe('Guardar');
    expect(collect($form['on_submit'])->firstWhere('type', 'show_toast')['message'])->toBe('Guardado');

    $createdCol = collect(blockByType($page, 'table')['columns'])->firstWhere('field_id', 'sys_created_at');
    expect($createdCol['label_override'])->toBe('Creado');

    $chart = blockByType(pageBySlug($manifest, 'dashboard'), 'chart');
    expect($chart['label'])->toBe('Comandas por estado');
});

it('keeps English chrome for an English app', function () {
    $manifest = scaffoldFor('en');
    $page = pageBySlug($manifest, 'comandas');

    expect(blockByType($page, 'button')['label'])->toBe('New Comanda');
    expect(blockByType($page, 'modal')['blocks'][0]['submit_label'])->toBe('Create');
    expect(blockByType(pageBySlug($manifest, 'dashboard'), 'chart')['label'])->toBe('Comandas by status');
});

it('adds a currency sum KPI to the dashboard', function () {
    $manifest = scaffoldFor('es-MX');
    $dashboard = pageBySlug($manifest, 'dashboard');
    $metrics = blockByType($dashboard, 'metric_grid');

    $sumKpi = collect($metrics['items'])->firstWhere('aggregation', 'sum');
    expect($sumKpi)->not->toBeNull();
    expect($sumKpi['format'])->toBe('currency');
    expect($sumKpi['label'])->toBe('Total Comandas');

    $totalField = collect($manifest['objects'][0]['fields'])->firstWhere('slug', 'total');
    expect($sumKpi['field_id'])->toBe($totalField['id']);
});

it('adds an average currency KPI alongside the total', function () {
    $manifest = scaffoldFor('en');
    $metrics = blockByType(pageBySlug($manifest, 'dashboard'), 'metric_grid');

    $avgKpi = collect($metrics['items'])->firstWhere('aggregation', 'avg');
    expect($avgKpi)->not->toBeNull();
    expect($avgKpi['format'])->toBe('currency');
    expect($avgKpi['label'])->toBe('Comandas average');

    $totalField = collect($manifest['objects'][0]['fields'])->firstWhere('slug', 'total');
    expect($avgKpi['field_id'])->toBe($totalField['id']);
});

it('leads the dashboard breakdown with a donut and adds a growth trend', function () {
    $manifest = scaffoldFor('es-MX');
    $dashboard = pageBySlug($manifest, 'dashboard');

    // The first chart stays the status breakdown, now a share-friendly donut.
    $chart = blockByType($dashboard, 'chart');
    expect($chart['chart_type'])->toBe('donut');
    expect($chart['label'])->toBe('Comandas por estado');

    // A sparkline trend over the always-present sys_created_at system field.
    $trend = blockByType($dashboard, 'sparkline');
    expect($trend)->not->toBeNull();
    expect($trend['x_field_id'])->toBe('sys_created_at');
    expect($trend['label'])->toBe('Comandas en el tiempo');
});

it('adds a value-by-status bar for a money object', function () {
    $manifest = scaffoldFor('en');
    $charts = blocksByType(pageBySlug($manifest, 'dashboard'), 'chart');

    $valueBar = collect($charts)->first(fn (array $c): bool => ($c['aggregation'] ?? null) === 'sum');
    expect($valueBar)->not->toBeNull();
    expect($valueBar['chart_type'])->toBe('bar');
    expect($valueBar['label'])->toBe('Comandas value by status');

    $totalField = collect($manifest['objects'][0]['fields'])->firstWhere('slug', 'total');
    expect($valueBar['y_field_id'])->toBe($totalField['id']);
});

it('builds a master-detail page for a parent with children', function () {
    $manifest = scaffoldWithChild('es-MX');

    $detail = pageBySlug($manifest, 'comandas_detail');
    expect($detail)->not->toBeNull();

    // The parent record itself.
    $recordDetail = blockByType($detail, 'record_detail');
    expect($recordDetail['record_id_expression'])->toBe('{{params.id}}');
    $comandas = collect($manifest['objects'])->firstWhere('slug', 'comandas');
    expect($recordDetail['object_id'])->toBe($comandas['id']);

    // Its children, scoped to this parent.
    $renglones = collect($manifest['objects'])->firstWhere('slug', 'renglones');
    $relatedList = blockByType($detail, 'related_list');
    expect($relatedList['object_id'])->toBe($renglones['id']);
    expect($relatedList['parent_id_expression'])->toBe('{{params.id}}');

    // The add-child form presets the relation back to this parent from the page id.
    $relField = collect($renglones['fields'])->firstWhere('type', 'relation');
    $form = blockByType($detail, 'modal')['blocks'][0];
    expect($form['object_id'])->toBe($renglones['id']);
    $createValues = collect($form['on_submit'])->firstWhere('type', 'create_record')['values'];
    expect($createValues[$relField['slug']])->toBe('{{params.id}}');
    // …and it does NOT ask the user to pick the parent again.
    expect(collect($form['fields'])->pluck('field_id'))->not->toContain($relField['id']);
});

it('derives a parent total from a child money field', function () {
    $manifest = scaffoldWithChild('es-MX');

    $comandas = collect($manifest['objects'])->firstWhere('slug', 'comandas');
    $sumRollup = collect($comandas['fields'])
        ->first(fn ($f) => ($f['type'] ?? null) === 'rollup' && ($f['aggregator'] ?? null) === 'sum');

    expect($sumRollup)->not->toBeNull();
    expect($sumRollup['name'])->toBe('Total Subtotal');

    $renglones = collect($manifest['objects'])->firstWhere('slug', 'renglones');
    $subtotal = collect($renglones['fields'])->firstWhere('slug', 'subtotal');
    expect($sumRollup['target_field_id'])->toBe($subtotal['id']);
});

it('links the parent list table to its detail page', function () {
    $manifest = scaffoldWithChild('es-MX');

    $table = blockByType(pageBySlug($manifest, 'comandas'), 'table');
    $action = collect($table['columns'])->firstWhere('type', 'action');
    expect($action)->not->toBeNull();
    expect($action['label'])->toBe('Abrir');
    expect($action['on_click'][0]['type'])->toBe('navigate');
    expect($action['on_click'][0]['to'])->toBe('/comandas_detail?id={{row.id}}');
});

it('produces a schema-valid manifest with the master-detail page', function () {
    $manifest = scaffoldWithChild('es-MX');

    $result = (new ManifestValidator)->validate($manifest);

    expect($result->errors)->toBe([]);
    expect($result->valid)->toBeTrue();
});

it('generates a POS screen for an order/line/product triad', function () {
    $manifest = scaffoldPos('es-MX');

    $pos = pageBySlug($manifest, 'pos');
    expect($pos)->not->toBeNull();

    // Product grid that adds a line to the open order on tap.
    $split = blockByType($pos, 'split_view');
    expect($split)->not->toBeNull();
    $grid = collect($split['left_blocks'])->firstWhere('type', 'card_grid');
    $platillos = collect($manifest['objects'])->firstWhere('slug', 'platillos');
    expect($grid['data_source']['object_id'])->toBe($platillos['id']);
    $create = collect($grid['on_click'])->firstWhere('type', 'create_record');
    $renglones = collect($manifest['objects'])->firstWhere('slug', 'renglones');
    expect($create['object_id'])->toBe($renglones['id']);
    expect($create['values']['comanda'])->toBe('{{params.order}}');
    expect($create['values']['platillo'])->toBe('{{row.id}}');

    // The cart: a table over lines filtered by the open order, with -/+ and remove.
    $cartTable = collect($split['right_blocks'])->firstWhere('type', 'table');
    expect($cartTable['data_source']['filter']['value_expression'])->toBe('{{params.order}}');
    $actions = collect($cartTable['columns'])->where('type', 'action')->pluck('label')->all();
    expect($actions)->toContain('−')->toContain('+')->toContain('×');

    // New-order button opens an order and routes to it as the page context.
    $btn = blockByType($pos, 'button');
    $nav = collect($btn['on_click'])->firstWhere('type', 'navigate');
    expect($nav['to'])->toBe('/pos?order={{record.id}}');

    // The cart guides when no order is open and only renders once one is.
    $hint = collect($split['right_blocks'])->firstWhere('type', 'alert');
    expect($hint['visibility']['expression'])->toBe('{{not params.order}}');
    expect($cartTable['visibility']['expression'])->toBe('{{params.order}}');
    expect(collect($split['right_blocks'])->firstWhere('type', 'record_detail')['visibility']['expression'])->toBe('{{params.order}}');
});

it('synthesizes the POS line economics and order total', function () {
    $manifest = scaffoldPos('es-MX');

    $renglones = collect($manifest['objects'])->firstWhere('slug', 'renglones');
    $precio = collect($renglones['fields'])->firstWhere('type', 'lookup');
    $subtotal = collect($renglones['fields'])->firstWhere('type', 'formula');
    expect($precio)->not->toBeNull();
    expect($subtotal['expression'])->toBe('{{cantidad * '.$precio['slug'].'}}');

    $comandas = collect($manifest['objects'])->firstWhere('slug', 'comandas');
    $total = collect($comandas['fields'])
        ->first(fn ($f) => ($f['type'] ?? null) === 'rollup' && ($f['aggregator'] ?? null) === 'sum');
    expect($total['target_field_id'])->toBe($subtotal['id']);
});

it('reuses an existing line subtotal instead of duplicating it', function () {
    $manifest = scaffoldPos('es-MX');
    $renglones = collect($manifest['objects'])->firstWhere('slug', 'renglones');

    // The model's "subtotal" currency field was converted to the formula in place
    // (same slug), and no duplicate "subtotal_2" was added.
    $subtotal = collect($renglones['fields'])->firstWhere('slug', 'subtotal');
    expect($subtotal['type'])->toBe('formula');
    expect(collect($renglones['fields'])->firstWhere('slug', 'subtotal_2'))->toBeNull();

    // It's gone from the create form (computed) but still a column in tables.
    $createForm = blockByType(pageBySlug($manifest, 'renglones'), 'modal')['blocks'][0];
    expect(collect($createForm['fields'])->pluck('field_id'))->not->toContain($subtotal['id']);

    // The order has exactly ONE sum rollup over that subtotal (no duplicate total).
    $comandas = collect($manifest['objects'])->firstWhere('slug', 'comandas');
    $sumOverSubtotal = collect($comandas['fields'])
        ->filter(fn ($f) => ($f['type'] ?? null) === 'rollup' && ($f['aggregator'] ?? null) === 'sum' && ($f['target_field_id'] ?? null) === $subtotal['id']);
    expect($sumOverSubtotal)->toHaveCount(1);
});

it('produces a schema-valid manifest with the POS screen', function () {
    $result = (new ManifestValidator)->validate(scaffoldPos('es-MX'));

    expect($result->errors)->toBe([]);
    expect($result->valid)->toBeTrue();
});

it('does not generate a POS screen without a priced product triad', function () {
    // The earlier parent/child (no priced product on the child's other side).
    $manifest = scaffoldWithChild('es-MX');
    expect(pageBySlug($manifest, 'pos'))->toBeNull();
});

it('does not turn a budget-priced triad into a POS (product-development, not commerce)', function () {
    // hitos belongs to two budget-priced parents — the exact shape that spawned
    // bogus "Punto de venta" pages. A budget is not a sale price.
    $manifest = scaffoldNpd();
    expect(posPageCount($manifest))->toBe(0);
    expect(pageBySlug($manifest, 'pos'))->toBeNull();
});

it('dedups multiple commerce triads to a single POS screen', function () {
    $manifest = scaffoldTwoPosTriads();
    expect(posPageCount($manifest))->toBe(1);
});

/**
 * @return array<string, mixed>
 */
function scaffoldM2M(array $links): array
{
    $base = [
        'schema_version' => '1.0.0', 'id' => 'app_scaffold_m2m', 'slug' => 'm2m', 'name' => 'M2M', 'version' => 1,
        'objects' => [], 'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
        'settings' => ['default_locale' => 'es-MX', 'default_currency' => 'MXN'],
    ];
    $scaffolder = app(AppScaffolder::class);
    // Route through normalizeSpec — the real MCP/builder path — so links get their
    // type preserved, `name` filled, and symmetric m2m pairs deduped.
    $spec = $scaffolder->normalizeSpec([
        'objects' => [
            ['name' => 'Escenas', 'slug' => 'escenas', 'fields' => [['name' => 'Numero', 'slug' => 'numero', 'type' => 'string', 'options' => null]]],
            ['name' => 'Elenco', 'slug' => 'elenco', 'fields' => [['name' => 'Personaje', 'slug' => 'personaje', 'type' => 'string', 'options' => null]]],
        ],
        'links' => $links,
    ]);

    return $scaffolder->assemble($base, $spec);
}

/** @return array<string, mixed>|null */
function m2mField(array $object): ?array
{
    return collect($object['fields'])->first(
        fn (array $f) => ($f['type'] ?? '') === 'relation' && ($f['cardinality'] ?? '') === 'many_to_many',
    );
}

it('builds a many_to_many link as a symmetric picker on both objects', function () {
    $manifest = scaffoldM2M([['from' => 'escenas', 'to' => 'elenco', 'type' => 'many_to_many']]);

    $escenas = collect($manifest['objects'])->firstWhere('slug', 'escenas');
    $elenco = collect($manifest['objects'])->firstWhere('slug', 'elenco');
    $escM2M = m2mField($escenas);
    $eleM2M = m2mField($elenco);

    expect($escM2M)->not->toBeNull()
        ->and($eleM2M)->not->toBeNull()
        ->and($escM2M['target_object_id'])->toBe($elenco['id'])
        ->and($eleM2M['target_object_id'])->toBe($escenas['id'])
        // Cross-linked so the runtime resolves from either side.
        ->and($escM2M['inverse_field_id'])->toBe($eleM2M['id'])
        ->and($eleM2M['inverse_field_id'])->toBe($escM2M['id']);

    // Manifest stays schema + semantically valid.
    $result = (new ManifestValidator)->validate($manifest);
    expect($result->errors)->toBe([]);
});

it('dedups a many_to_many link given in both directions', function () {
    $manifest = scaffoldM2M([
        ['from' => 'escenas', 'to' => 'elenco', 'type' => 'many_to_many'],
        ['from' => 'elenco', 'to' => 'escenas', 'type' => 'many_to_many'],
    ]);

    // Exactly one m2m field per object (a symmetric pair), not two.
    foreach (['escenas', 'elenco'] as $slug) {
        $object = collect($manifest['objects'])->firstWhere('slug', $slug);
        $count = collect($object['fields'])->filter(
            fn (array $f) => ($f['type'] ?? '') === 'relation' && ($f['cardinality'] ?? '') === 'many_to_many',
        )->count();
        expect($count)->toBe(1);
    }
});

it('scaffolded apps produce no design-lint warnings', function () {
    $manifests = [
        'crm' => scaffoldFor('es-MX'),
        'master-detail' => scaffoldWithChild('es-MX'),
        'pos' => scaffoldPos('es-MX'),
    ];

    foreach ($manifests as $label => $manifest) {
        $smells = collect((new ManifestValidator)->validate($manifest)->warnings)
            ->filter(fn ($w) => $w->code === 'design_smell')
            ->map(fn ($w) => $w->path.': '.$w->message)
            ->all();
        expect($smells)->toBe([], "expected no design smells for {$label}");
    }
});

it('transliterates accented single_select values and field names into clean slugs', function () {
    $scaffolder = app(AppScaffolder::class);
    $coercions = [];

    $field = $scaffolder->normalizeField([
        'name' => 'Categoría',
        'type' => 'single_select',
        'options' => [
            ['value' => 'Garantías', 'label' => 'Garantías'],
            ['value' => 'Crítica', 'label' => 'Crítica'],
            ['value' => 'Teléfono', 'label' => 'Teléfono'],
        ],
    ], [], $coercions);

    // Accents transliterate (garantias) instead of collapsing to "garant_as".
    expect(collect($field['options'])->pluck('value')->all())
        ->toBe(['garantias', 'critica', 'telefono']);
    // Labels keep their accents; only the machine slug is ASCII.
    expect($field['options'][0]['label'])->toBe('Garantías');
    expect($field['slug'])->toBe('categoria');
});

it('keeps a wide object of 21 fields instead of silently truncating to 12', function () {
    // add_object once dropped every field past the 12th with a success response
    // and no warning — a weekly-ops fact table lost 9 columns invisibly.
    $raw = [];
    for ($i = 1; $i <= 21; $i++) {
        $raw[] = ['name' => "Metric {$i}", 'type' => 'number'];
    }
    $coercions = [];
    $fields = app(AppScaffolder::class)->normalizeFields($raw, $coercions);

    expect($fields)->toHaveCount(21)
        ->and($coercions)->toBe([]);
});

it('truncates beyond 40 fields but never silently — it emits a coercion note', function () {
    $raw = [];
    for ($i = 1; $i <= 45; $i++) {
        $raw[] = ['name' => "Metric {$i}", 'type' => 'number'];
    }
    $coercions = [];
    $fields = app(AppScaffolder::class)->normalizeFields($raw, $coercions);

    expect($fields)->toHaveCount(40)
        ->and(collect($coercions)->contains(fn ($c) => str_contains($c, 'dropped')))->toBeTrue();
});
