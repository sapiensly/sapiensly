<?php

use App\Services\Manifest\AppScaffolder;
use App\Services\Manifest\ManifestValidator;
use App\Support\Locale\Inflector;

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

/**
 * Find a block by type ANYWHERE on the page, including inside a container.
 *
 * A list page's alternative views (board, calendar, timeline) now live in tabs
 * beside the table rather than stacked under it, so a top-level scan no longer
 * finds them — they moved, they did not disappear.
 *
 * @param  array<string, mixed>  $page
 * @return array<string, mixed>|null
 */
function blockByTypeDeep(array $page, string $type): ?array
{
    $walk = function (array $blocks) use (&$walk, $type): ?array {
        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === $type) {
                return $block;
            }
            foreach (['blocks', 'left_blocks', 'right_blocks'] as $key) {
                $found = $walk($block[$key] ?? []);
                if ($found !== null) {
                    return $found;
                }
            }
            foreach (['tabs', 'sections'] as $key) {
                foreach ($block[$key] ?? [] as $child) {
                    $found = $walk($child['blocks'] ?? []);
                    if ($found !== null) {
                        return $found;
                    }
                }
            }
        }

        return null;
    };

    return $walk($page['blocks'] ?? []);
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
    $gantt = blockByTypeDeep($page, 'gantt');
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
    expect(blockByTypeDeep(pageBySlug($manifest, 'comandas'), 'gantt'))->toBeNull();
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
    $calendar = blockByTypeDeep($page, 'calendar');
    expect($calendar)->not->toBeNull();

    $fields = collect($manifest['objects'][0]['fields']);
    expect($calendar['date_field_id'])->toBe($fields->firstWhere('slug', 'fecha')['id'])
        ->and($calendar['title_field_id'])->toBe($fields->firstWhere('slug', 'titulo')['id'])
        ->and($calendar['color_field_id'])->toBe($fields->firstWhere('slug', 'estado')['id']);

    // A lone date is an event, not a span — no Gantt.
    expect(blockByTypeDeep($page, 'gantt'))->toBeNull();
    expect(app(ManifestValidator::class)->validate($manifest)->valid)->toBeTrue();
});

it('prefers a Gantt over a calendar when an object spans two dates', function () {
    $manifest = scaffoldPlan('en');
    $page = pageBySlug($manifest, 'tasks');
    expect(blockByTypeDeep($page, 'gantt'))->not->toBeNull()
        ->and(blockByTypeDeep($page, 'calendar'))->toBeNull();
});

it('scaffolds an editable kanban with colour-coded status options', function () {
    $manifest = scaffoldFor('es-MX');

    $page = pageBySlug($manifest, 'comandas');
    $kanban = blockByTypeDeep($page, 'kanban');
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

    $createdCol = collect(blockByTypeDeep($page, 'table')['columns'])->firstWhere('field_id', 'sys_created_at');
    expect($createdCol['label_override'])->toBe('Creado');

    $chart = blockByType(pageBySlug($manifest, 'dashboard'), 'chart');
    expect($chart['label'])->toBe('Comandas por estado');
});

it('keeps English chrome for an English app', function () {
    $manifest = scaffoldFor('en');
    $page = pageBySlug($manifest, 'comandas');

    expect(blockByType($page, 'button')['label'])->toBe('New Comanda');
    expect(blockByType($page, 'modal')['blocks'][0]['submit_label'])->toBe('Create');
    // Named after the field it groups by, which in this fixture is called
    // "Estado" — the chrome is English, the author's field name is theirs.
    expect(blockByType(pageBySlug($manifest, 'dashboard'), 'chart')['label'])->toBe('Comandas by estado');
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
    // Named for the children it adds up, not for the field it sums: naming it
    // after the field produced "Total Costo Total" on an object whose amount
    // was already called "Costo Total".
    expect($sumRollup['name'])->toBe('Total Renglones');

    $renglones = collect($manifest['objects'])->firstWhere('slug', 'renglones');
    $subtotal = collect($renglones['fields'])->firstWhere('slug', 'subtotal');
    expect($sumRollup['target_field_id'])->toBe($subtotal['id']);
});

it('links the parent list table to its detail page', function () {
    $manifest = scaffoldWithChild('es-MX');

    $table = blockByTypeDeep(pageBySlug($manifest, 'comandas'), 'table');
    // Addressed by label: the row carries more than one action now (edit opens
    // a modal in place), and this test is about the one that navigates.
    $action = collect($table['columns'])->firstWhere('label', 'Abrir');
    expect($action)->not->toBeNull();
    expect($action['type'])->toBe('action');
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

it('recognizes a French commerce triad and localizes the chrome (per-locale lexicon)', function () {
    $base = [
        'schema_version' => '1.0.0', 'id' => 'app_bistro', 'slug' => 'bistro', 'name' => 'Bistro', 'version' => 1,
        'objects' => [], 'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
        'settings' => ['default_locale' => 'fr-FR', 'default_currency' => 'EUR'],
    ];
    $spec = [
        'objects' => [
            ['name' => 'Commandes', 'slug' => 'commandes', 'fields' => [
                ['name' => 'Folio', 'slug' => 'folio', 'type' => 'string', 'options' => null],
                ['name' => 'Statut', 'slug' => 'statut', 'type' => 'single_select', 'options' => [['value' => 'ouverte', 'label' => 'Ouverte']]],
            ]],
            ['name' => 'Plats', 'slug' => 'plats', 'fields' => [
                ['name' => 'Nom', 'slug' => 'nom', 'type' => 'string', 'options' => null],
                ['name' => 'Prix', 'slug' => 'prix', 'type' => 'currency', 'options' => null],
            ]],
            ['name' => 'Lignes', 'slug' => 'lignes', 'fields' => [
                ['name' => 'Quantite', 'slug' => 'quantite', 'type' => 'number', 'options' => null],
            ]],
        ],
        'links' => [
            ['from' => 'lignes', 'to' => 'commandes', 'name' => 'commande'],
            ['from' => 'lignes', 'to' => 'plats', 'name' => 'plat'],
        ],
    ];
    $scaffolder = app(AppScaffolder::class);
    $manifest = $scaffolder->assemble($base, $scaffolder->normalizeSpec($spec));

    // The POS register is generated — invisible before per-locale vocab.
    expect(posPageCount($manifest))->toBe(1);
    $pos = collect($manifest['pages'])->first(fn (array $p) => collect($p['blocks'])->contains(fn (array $b) => ($b['type'] ?? '') === 'split_view'));
    expect($pos['name'])->toBe('Point de vente');
    expect(blockByType($pos, 'button')['label'])->toBe('Nouvelle commande');

    // The line got its economics (unit-price lookup + subtotal formula), and it
    // reused the existing "Quantite" field rather than adding a duplicate.
    $lignes = collect($manifest['objects'])->firstWhere('slug', 'lignes');
    expect(collect($lignes['fields'])->whereIn('type', ['lookup', 'formula'])->count())->toBeGreaterThanOrEqual(2);
    expect(collect($lignes['fields'])->where('type', 'number')->where('slug', 'quantite')->count())->toBe(1);

    // Chrome on an ordinary list page is French too ("Ajouter …", not "New …").
    $platsPage = pageBySlug($manifest, 'plats');
    expect(blockByType($platsPage, 'button')['label'])->toStartWith('Ajouter ');

    expect((new ManifestValidator)->validate($manifest)->errors)->toBe([]);
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

/*
|--------------------------------------------------------------------------
| Contact field types
|--------------------------------------------------------------------------
|
| The SYSTEM prompt asks the model for email/url/phone by name and explains
| that they validate the format. The model obliges — and the normalizer used
| to throw the answer away, so every generated app stored contact data as
| free text. These pin the whole round trip.
|
*/

it('keeps the contact types the prompt asks the model for', function () {
    $spec = app(AppScaffolder::class)->normalizeSpec([
        'objects' => [[
            'name' => 'Tickets',
            'slug' => 'tickets',
            'fields' => [
                ['name' => 'Asunto', 'slug' => 'asunto', 'type' => 'string'],
                ['name' => 'Correo del solicitante', 'slug' => 'correo', 'type' => 'email'],
                ['name' => 'Sitio', 'slug' => 'sitio', 'type' => 'url'],
                ['name' => 'Teléfono', 'slug' => 'telefono', 'type' => 'phone'],
            ],
        ]],
        'links' => [],
    ]);

    expect(array_column($spec['objects'][0]['fields'], 'type'))
        ->toBe(['string', 'email', 'url', 'phone']);
});

it('assembles a schema-valid manifest that keeps the email field typed', function () {
    $base = [
        'schema_version' => '1.0.0',
        'id' => 'app_scaffold_mail',
        'slug' => 'helpdesk',
        'name' => 'Helpdesk',
        'version' => 1,
        'objects' => [],
        'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
        'settings' => ['default_locale' => 'es-MX', 'default_currency' => 'MXN'],
    ];

    $manifest = app(AppScaffolder::class)->assemble($base, [
        'objects' => [[
            'name' => 'Tickets',
            'slug' => 'tickets',
            'fields' => [
                ['name' => 'Asunto', 'slug' => 'asunto', 'type' => 'string', 'options' => null],
                ['name' => 'Correo', 'slug' => 'correo', 'type' => 'email', 'options' => null],
            ],
        ]],
        'links' => [],
    ]);

    $correo = collect($manifest['objects'][0]['fields'])->firstWhere('slug', 'correo');

    expect($correo['type'])->toBe('email')
        ->and((new ManifestValidator)->validate($manifest)->errors)->toBe([]);
});

it('accepts email through the typed add_field path too', function () {
    $coercions = [];
    $field = app(AppScaffolder::class)->normalizeField(
        ['name' => 'Correo', 'slug' => 'correo', 'type' => 'email'],
        [],
        $coercions,
    );

    expect($field['type'])->toBe('email')
        ->and($coercions)->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Integrity the model is never asked for
|--------------------------------------------------------------------------
|
| The spec the model returns is name/slug/type/options — no `required`, no
| `default` — so a generated app used to accept a record with every field
| null, and a board grouped by a status nobody had set.
|
*/

it('requires the field that labels the record', function () {
    $manifest = scaffoldFor('es-MX');
    $fields = collect($manifest['objects'][0]['fields']);

    // Folio is the first string field: it titles every card and table row.
    expect($fields->firstWhere('slug', 'folio')['required'] ?? false)->toBeTrue()
        // Nothing else is forced — the scaffold does not guess the business.
        ->and($fields->firstWhere('slug', 'total')['required'] ?? false)->toBeFalse();
});

it('defaults the status the board groups by to its first option', function () {
    $manifest = scaffoldFor('es-MX');
    $estado = collect($manifest['objects'][0]['fields'])->firstWhere('slug', 'estado');

    expect($estado['default'])->toBe('abierta');

    // …which is exactly the field the kanban groups by, so a new record lands
    // in a column instead of outside the board.
    $page = pageBySlug($manifest, 'comandas');
    $kanban = blockByTypeDeep($page, 'kanban');
    expect($kanban['group_by_field_id'])->toBe($estado['id']);
});

it('leaves a second select alone — a default there would be an opinion', function () {
    $base = [
        'schema_version' => '1.0.0',
        'id' => 'app_scaffold_int',
        'slug' => 'tickets',
        'name' => 'Tickets',
        'version' => 1,
        'objects' => [],
        'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
        'settings' => ['default_locale' => 'es-MX', 'default_currency' => 'MXN'],
    ];

    $manifest = app(AppScaffolder::class)->assemble($base, [
        'objects' => [[
            'name' => 'Tickets',
            'slug' => 'tickets',
            'fields' => [
                ['name' => 'Asunto', 'slug' => 'asunto', 'type' => 'string', 'options' => null],
                ['name' => 'Estado', 'slug' => 'estado', 'type' => 'single_select', 'options' => [
                    ['value' => 'nuevo', 'label' => 'Nuevo'],
                    ['value' => 'resuelto', 'label' => 'Resuelto'],
                ]],
                ['name' => 'Prioridad', 'slug' => 'prioridad', 'type' => 'single_select', 'options' => [
                    ['value' => 'baja', 'label' => 'Baja'],
                    ['value' => 'urgente', 'label' => 'Urgente'],
                ]],
            ],
        ]],
        'links' => [],
    ]);

    $fields = collect($manifest['objects'][0]['fields']);

    expect($fields->firstWhere('slug', 'estado')['default'])->toBe('nuevo')
        ->and($fields->firstWhere('slug', 'prioridad'))->not->toHaveKey('default')
        ->and((new ManifestValidator)->validate($manifest)->errors)->toBe([]);
});

it('never overrides a value the typed path set explicitly', function () {
    $base = [
        'schema_version' => '1.0.0',
        'id' => 'app_scaffold_exp',
        'slug' => 'notes',
        'name' => 'Notes',
        'version' => 1,
        'objects' => [],
        'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
        'settings' => ['default_locale' => 'en', 'default_currency' => 'USD'],
    ];

    $manifest = app(AppScaffolder::class)->assemble($base, [
        'objects' => [[
            'name' => 'Notes',
            'slug' => 'notes',
            'fields' => [
                ['name' => 'Title', 'slug' => 'title', 'type' => 'string', 'options' => null, 'config' => ['required' => false]],
            ],
        ]],
        'links' => [],
    ]);

    expect($manifest['objects'][0]['fields'][0]['required'])->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Roles that mean something
|--------------------------------------------------------------------------
|
| The scaffold shipped `admin` + `user` and zero policies, and an app with
| no object policies is open-within-visibility: every member could delete
| every record while the Access panel promised a distinction.
|
*/

/**
 * A base with the two roles a real app is created with (initialManifest), which
 * the fixtures above deliberately do not have.
 *
 * @return array<string, mixed>
 */
function twoRoleBase(): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => 'app_scaffold_pol',
        'slug' => 'helpdesk',
        'name' => 'Helpdesk',
        'version' => 1,
        'objects' => [],
        'pages' => [],
        'permissions' => ['roles' => [
            ['id' => 'rol_admin0000001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => false],
            ['id' => 'rol_user00000001', 'slug' => 'user', 'name' => 'User', 'is_default' => true],
        ]],
        'settings' => ['default_locale' => 'es-MX', 'default_currency' => 'MXN'],
    ];
}

/**
 * @return array<string, mixed>
 */
function scaffoldWithRoles(): array
{
    return app(AppScaffolder::class)->assemble(twoRoleBase(), [
        'objects' => [
            ['name' => 'Tickets', 'slug' => 'tickets', 'fields' => [
                ['name' => 'Asunto', 'slug' => 'asunto', 'type' => 'string', 'options' => null],
            ]],
            ['name' => 'Respuestas', 'slug' => 'respuestas', 'fields' => [
                ['name' => 'Mensaje', 'slug' => 'mensaje', 'type' => 'long_text', 'options' => null],
            ]],
        ],
        'links' => [['from' => 'respuestas', 'to' => 'tickets', 'name' => 'ticket']],
    ]);
}

it('states what each role may do, on every object', function () {
    $manifest = scaffoldWithRoles();
    $policies = collect($manifest['permissions']['object_policies'] ?? []);
    $rolesById = collect($manifest['permissions']['roles'])->keyBy('id');

    // Complete: an object with ANY policy denies every role that has no row on
    // it, so a partial matrix would lock people out of half the app.
    expect($policies)->toHaveCount(2 * 2);

    $byRole = $policies->groupBy(fn (array $p): string => $rolesById[$p['role_id']]['slug']);

    expect($byRole['admin']->every(fn (array $p): bool => in_array('delete', $p['actions'], true)))->toBeTrue()
        ->and($byRole['user']->every(fn (array $p): bool => ! in_array('delete', $p['actions'], true)))->toBeTrue()
        ->and($byRole['user']->every(fn (array $p): bool => in_array('create', $p['actions'], true)))->toBeTrue()
        ->and((new ManifestValidator)->validate($manifest)->errors)->toBe([]);
});

it('never widens a role an author narrowed, and covers a new object with what it already had', function () {
    $manifest = scaffoldWithRoles();

    // The author cuts `user` down to read-only…
    $manifest['permissions']['object_policies'] = collect($manifest['permissions']['object_policies'])
        ->map(function (array $p): array {
            if ($p['role_id'] === 'rol_user00000001') {
                $p['actions'] = ['read'];
            }

            return $p;
        })->values()->all();

    // …then a third object arrives.
    $manifest['objects'][] = [
        'id' => 'obj_later0000001',
        'slug' => 'notas',
        'name' => 'Notas',
        'fields' => [['id' => 'fld_later0000001', 'slug' => 'texto', 'name' => 'Texto', 'type' => 'string']],
    ];

    $updated = app(AppScaffolder::class)->ensureObjectPolicies($manifest);
    $forNew = collect($updated['permissions']['object_policies'])->where('object_id', 'obj_later0000001');

    expect($forNew)->toHaveCount(2)
        ->and($forNew->firstWhere('role_id', 'rol_user00000001')['actions'])->toBe(['read'])
        ->and($forNew->firstWhere('role_id', 'rol_admin0000001')['actions'])->toContain('delete');
});

it('does not hand a portal role anything it was not explicitly given', function () {
    $manifest = scaffoldWithRoles();
    $visitor = ['id' => 'rol_visitor00001', 'slug' => 'visitante', 'name' => 'Visitante', 'is_default' => false];
    $manifest['permissions']['roles'][] = $visitor;
    $manifest['permissions']['public'] = ['enabled' => true, 'role_id' => $visitor['id']];

    $manifest['objects'][] = [
        'id' => 'obj_public00001',
        'slug' => 'publicas',
        'name' => 'Publicas',
        'fields' => [['id' => 'fld_public00001', 'slug' => 'texto', 'name' => 'Texto', 'type' => 'string']],
    ];

    $updated = app(AppScaffolder::class)->ensureObjectPolicies($manifest);
    $forNew = collect($updated['permissions']['object_policies'])->where('object_id', 'obj_public00001');

    // Deny-by-default is the whole point of a portal role: automation must
    // never be what puts tenant data in front of strangers.
    expect($forNew->firstWhere('role_id', $visitor['id']))->toBeNull()
        ->and($forNew)->toHaveCount(2);
});

/**
 * A generated app must be able to CHANGE a record, not only add one. Without
 * this the create form is the whole write surface: every field is fixed at
 * insert time, and correcting a typo means deleting and retyping the record.
 *
 * The path has three parts that only work together — a modal, an edit form
 * reading the id the modal was handed, and a row action that hands it over
 * along with the row's current values to seed the inputs. Assert all three.
 */
it('gives every list page a working edit path', function () {
    $manifest = scaffoldFor('es-MX');
    $page = pageBySlug($manifest, 'comandas');

    $editModal = collect($page['blocks'])
        ->filter(fn (array $b): bool => ($b['type'] ?? null) === 'modal')
        ->first(fn (array $b): bool => ($b['blocks'][0]['mode'] ?? null) === 'edit');

    expect($editModal)->not->toBeNull()
        ->and($editModal['title'])->toBe('Editar Comanda');

    $form = $editModal['blocks'][0];
    expect($form['record_id_expression'])->toBe('{{params.record_id}}')
        ->and($form['submit_label'])->toBe('Guardar cambios');

    // The submit must UPDATE the row it opened — an edit form wired to
    // create_record would silently fork a duplicate on every save.
    $update = collect($form['on_submit'])->firstWhere('type', 'update_record');
    expect($update)->not->toBeNull()
        ->and($update['record_id_expression'])->toBe('{{params.record_id}}')
        ->and($update['object_id'])->toBe($form['object_id']);

    $action = collect(blockByTypeDeep($page, 'table')['columns'])->firstWhere('label', 'Editar');
    expect($action)->not->toBeNull()
        ->and($action['type'])->toBe('action');

    $open = $action['on_click'][0];
    expect($open['type'])->toBe('open_modal')
        ->and($open['modal_block_id'])->toBe($editModal['id'])
        ->and($open['params']['record_id'])->toBe('{{row.id}}')
        // Without the row's values the modal opens blank, and a blank edit is
        // how a save quietly wipes every field the user did not retype.
        ->and($open['params']['record'])->toBe('{{row.data}}');
});

it('keeps the edit path in English for an English app', function () {
    $page = pageBySlug(scaffoldFor('en'), 'comandas');

    $editModal = collect($page['blocks'])
        ->filter(fn (array $b): bool => ($b['type'] ?? null) === 'modal')
        ->first(fn (array $b): bool => ($b['blocks'][0]['mode'] ?? null) === 'edit');

    expect($editModal['title'])->toBe('Edit Comanda')
        ->and($editModal['blocks'][0]['submit_label'])->toBe('Save changes')
        ->and(collect(blockByTypeDeep($page, 'table')['columns'])->firstWhere('label', 'Edit'))->not->toBeNull();
});

it('leaves derived fields out of the edit form, as it does the create form', function () {
    // A rollup/lookup/formula is computed; offering it in an edit form would
    // present a writable input for a value the save cannot accept.
    $manifest = scaffoldWithChild('es-MX');
    $page = pageBySlug($manifest, 'comandas');

    $editModal = collect($page['blocks'])
        ->filter(fn (array $b): bool => ($b['type'] ?? null) === 'modal')
        ->first(fn (array $b): bool => ($b['blocks'][0]['mode'] ?? null) === 'edit');

    $object = collect($manifest['objects'])->firstWhere('slug', 'comandas');
    $derivedIds = collect($object['fields'])
        ->filter(fn (array $f): bool => in_array($f['type'], ['rollup', 'lookup', 'formula'], true))
        ->pluck('id');

    expect($derivedIds)->not->toBeEmpty();

    $formIds = collect($editModal['blocks'][0]['fields'])->pluck('field_id');
    foreach ($derivedIds as $id) {
        expect($formIds)->not->toContain($id);
    }
});

/**
 * A schema cannot tell a status from a taxonomy — both are a select with
 * options — so the scaffolder used to take the FIRST one for everything: the
 * default, the board, the breakdown's title. On anything richer than a toy
 * object that is the wrong field, and the consequences are not cosmetic.
 *
 * @return array<string, mixed>
 */
function scaffoldWorkOrders(): array
{
    $base = [
        'schema_version' => '1.0.0', 'id' => 'app_scaffold_wo1', 'slug' => 'ot', 'name' => 'OT', 'version' => 1,
        'objects' => [], 'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
        'settings' => ['default_locale' => 'es-MX', 'default_currency' => 'MXN'],
    ];

    $spec = [
        'objects' => [[
            'name' => 'Ordenes', 'slug' => 'ordenes', 'fields' => [
                ['name' => 'Folio', 'slug' => 'folio', 'type' => 'string', 'options' => null],
                // The classification comes FIRST, exactly as the model tends to
                // emit it, and the real status second.
                ['name' => 'Tipo de Servicio', 'slug' => 'tipo_servicio', 'type' => 'single_select', 'options' => [
                    ['value' => 'preventivo', 'label' => 'Preventivo'],
                    ['value' => 'correctivo', 'label' => 'Correctivo'],
                ]],
                ['name' => 'Estado', 'slug' => 'estado', 'type' => 'single_select', 'options' => [
                    ['value' => 'recibida', 'label' => 'Recibida'],
                    ['value' => 'en_ejecucion', 'label' => 'En ejecucion'],
                    ['value' => 'cerrada', 'label' => 'Cerrada'],
                ]],
                ['name' => 'Fecha de Apertura', 'slug' => 'fecha_apertura', 'type' => 'date', 'options' => null],
                ['name' => 'Fecha de Cierre', 'slug' => 'fecha_cierre', 'type' => 'date', 'options' => null],
            ],
        ]],
        'links' => [],
    ];

    return app(AppScaffolder::class)->assemble($base, $spec);
}

it('defaults the status field, not whichever select came first', function () {
    $fields = collect(scaffoldWorkOrders()['objects'][0]['fields']);

    // A classification with a silent default is a value nobody chose: every
    // order would open claiming to be preventive maintenance.
    expect($fields->firstWhere('slug', 'tipo_servicio'))->not->toHaveKey('default')
        ->and($fields->firstWhere('slug', 'estado')['default'])->toBe('recibida');
});

it('groups the board by the status, so a new record lands in a column', function () {
    $manifest = scaffoldWorkOrders();
    $kanban = blockByTypeDeep(pageBySlug($manifest, 'ordenes'), 'kanban');
    $estado = collect($manifest['objects'][0]['fields'])->firstWhere('slug', 'estado');

    expect($kanban['group_by_field_id'])->toBe($estado['id']);
});

it('titles a breakdown after the field it actually groups by', function () {
    $chart = blockByType(pageBySlug(scaffoldWorkOrders(), 'dashboard'), 'chart');

    expect($chart['label'])->toBe('Ordenes por estado');
});

it('builds no board for an object whose only select is a classification', function () {
    // Customers do not move left to right across contract types.
    $base = [
        'schema_version' => '1.0.0', 'id' => 'app_scaffold_cl1', 'slug' => 'cl', 'name' => 'CL', 'version' => 1,
        'objects' => [], 'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
        'settings' => ['default_locale' => 'es-MX', 'default_currency' => 'MXN'],
    ];
    $spec = ['objects' => [[
        'name' => 'Clientes', 'slug' => 'clientes', 'fields' => [
            ['name' => 'Razon Social', 'slug' => 'razon_social', 'type' => 'string', 'options' => null],
            ['name' => 'Tipo de Contrato', 'slug' => 'tipo_contrato', 'type' => 'single_select', 'options' => [
                ['value' => 'preventivo', 'label' => 'Preventivo'],
                ['value' => 'integral', 'label' => 'Integral'],
            ]],
            ['name' => 'Fecha de Alta', 'slug' => 'fecha_alta', 'type' => 'date', 'options' => null],
        ],
    ]], 'links' => []];

    $manifest = app(AppScaffolder::class)->assemble($base, $spec);
    $page = pageBySlug($manifest, 'clientes');
    $fields = collect($manifest['objects'][0]['fields']);

    expect(blockByTypeDeep($page, 'kanban'))->toBeNull()
        // A signup date is not something you look forward to; a month grid of
        // them opened the customer list, above the customers.
        ->and(blockByTypeDeep($page, 'calendar'))->toBeNull()
        ->and($fields->firstWhere('slug', 'tipo_contrato'))->not->toHaveKey('default')
        // …but the breakdown is still worth drawing, honestly titled.
        ->and(blockByType(pageBySlug($manifest, 'dashboard'), 'chart')['label'])
        ->toBe('Clientes por tipo de contrato');
});

it('draws a Gantt only for dates that describe a span', function () {
    // apertura → cierre is work being done; installed → last serviced is not.
    $withSpan = blockByTypeDeep(pageBySlug(scaffoldWorkOrders(), 'ordenes'), 'gantt');
    expect($withSpan)->not->toBeNull();

    $base = [
        'schema_version' => '1.0.0', 'id' => 'app_scaffold_eq1', 'slug' => 'eq', 'name' => 'EQ', 'version' => 1,
        'objects' => [], 'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
        'settings' => ['default_locale' => 'es-MX', 'default_currency' => 'MXN'],
    ];
    $spec = ['objects' => [[
        'name' => 'Equipos', 'slug' => 'equipos', 'fields' => [
            ['name' => 'Numero de Serie', 'slug' => 'numero_serie', 'type' => 'string', 'options' => null],
            ['name' => 'Fecha de Instalacion', 'slug' => 'fecha_instalacion', 'type' => 'date', 'options' => null],
            ['name' => 'Ultima Revision', 'slug' => 'ultima_revision', 'type' => 'date', 'options' => null],
        ],
    ]], 'links' => []];

    expect(blockByTypeDeep(pageBySlug(app(AppScaffolder::class)->assemble($base, $spec), 'equipos'), 'gantt'))->toBeNull();
});

it('says what each object is called, so nothing downstream has to guess', function () {
    $manifest = scaffoldWorkOrders();
    $object = $manifest['objects'][0];
    $folio = collect($object['fields'])->firstWhere('slug', 'folio');

    expect($object['primary_display_field_id'])->toBe($folio['id']);
});

it('keeps the way into the detail page when the table moves into a tab', function () {
    // The alternative views live in tabs now, so the table is no longer a
    // top-level block. A scan that only looked at the top level dropped the
    // "open" row action — and with it the only route to the detail page, which
    // then rendered empty because nothing provided its {{params.id}}.
    $manifest = scaffoldWithChild('es-MX');
    $table = blockByTypeDeep(pageBySlug($manifest, 'comandas'), 'table');

    $open = collect($table['columns'])->firstWhere('label', 'Abrir');
    expect($open)->not->toBeNull()
        ->and($open['on_click'][0]['to'])->toBe('/comandas_detail?id={{row.id}}');

    // Said another way: the lint that caught it stays quiet.
    $smells = collect((new ManifestValidator)->validate($manifest)->warnings)
        ->filter(fn ($w) => $w->code === 'design_smell')
        ->all();
    expect($smells)->toBe([]);
});

it('opens a list page on the list, with the other views one click away', function () {
    $page = pageBySlug(scaffoldWorkOrders(), 'ordenes');
    $tabs = blockByType($page, 'tabs');

    expect($tabs)->not->toBeNull()
        ->and(collect($tabs['tabs'])->pluck('label')->all())
        ->toBe(['Lista', 'Cronograma', 'Tablero']);

    // The list is first: it is what someone came to the page for.
    expect($tabs['tabs'][0]['blocks'][0]['type'])->toBe('table');
});

it('derives a line total from quantity and unit price, sale or not', function () {
    // The POS pass needs an order←line→product triad, so a part used on a work
    // order never reached it and its total stayed a number to type by hand,
    // beside the two numbers it is the product of.
    $base = [
        'schema_version' => '1.0.0', 'id' => 'app_scaffold_rf1', 'slug' => 'rf', 'name' => 'RF', 'version' => 1,
        'objects' => [], 'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
        'settings' => ['default_locale' => 'es-MX', 'default_currency' => 'MXN'],
    ];
    $spec = [
        'objects' => [
            ['name' => 'Ordenes', 'slug' => 'ordenes', 'fields' => [
                ['name' => 'Folio', 'slug' => 'folio', 'type' => 'string', 'options' => null],
            ]],
            ['name' => 'Refacciones', 'slug' => 'refacciones', 'fields' => [
                ['name' => 'Descripcion', 'slug' => 'descripcion', 'type' => 'string', 'options' => null],
                ['name' => 'Cantidad', 'slug' => 'cantidad', 'type' => 'number', 'options' => null],
                ['name' => 'Costo Unitario', 'slug' => 'costo_unitario', 'type' => 'currency', 'options' => null],
                ['name' => 'Costo Total', 'slug' => 'costo_total', 'type' => 'currency', 'options' => null],
            ]],
        ],
        'links' => [['from' => 'refacciones', 'to' => 'ordenes', 'name' => 'orden']],
    ];

    $manifest = app(AppScaffolder::class)->assemble($base, $spec);
    $refacciones = collect($manifest['objects'])->firstWhere('slug', 'refacciones');
    $total = collect($refacciones['fields'])->firstWhere('slug', 'costo_total');

    expect($total['type'])->toBe('formula')
        ->and($total['expression'])->toBe('{{cantidad * costo_unitario}}');

    // And the parent sums the line TOTAL, not the price per piece — adding up
    // unit prices across lines answers no question anyone has.
    $ordenes = collect($manifest['objects'])->firstWhere('slug', 'ordenes');
    $sum = collect($ordenes['fields'])->first(
        fn (array $f): bool => ($f['type'] ?? null) === 'rollup' && ($f['aggregator'] ?? null) === 'sum'
    );

    expect($sum)->not->toBeNull()
        ->and($sum['target_field_id'])->toBe($total['id']);
});

/**
 * A six-object app, the shape that exposed the dashboard's arithmetic: one KPI
 * per object plus two per object with money, a donut per select and a trend per
 * object came to nineteen blocks — some four thousand pixels of scrolling
 * before a single record existed. Nobody lays that out on purpose.
 *
 * @return array<string, mixed>
 */
function scaffoldOperation(): array
{
    $base = [
        'schema_version' => '1.0.0', 'id' => 'app_scaffold_op1', 'slug' => 'op', 'name' => 'Op', 'version' => 1,
        'objects' => [], 'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
        'settings' => ['default_locale' => 'es-MX', 'default_currency' => 'MXN'],
    ];

    $spec = [
        'objects' => [
            ['name' => 'Clientes', 'slug' => 'clientes', 'fields' => [
                ['name' => 'Razon Social', 'slug' => 'razon_social', 'type' => 'string', 'options' => null],
                ['name' => 'Tipo de Contrato', 'slug' => 'tipo_contrato', 'type' => 'single_select', 'options' => [
                    ['value' => 'preventivo', 'label' => 'Preventivo'], ['value' => 'integral', 'label' => 'Integral'],
                ]],
            ]],
            ['name' => 'Sedes', 'slug' => 'sedes', 'fields' => [
                ['name' => 'Nombre', 'slug' => 'nombre', 'type' => 'string', 'options' => null],
            ]],
            ['name' => 'Ordenes', 'slug' => 'ordenes', 'fields' => [
                ['name' => 'Folio', 'slug' => 'folio', 'type' => 'string', 'options' => null],
                ['name' => 'Estado', 'slug' => 'estado', 'type' => 'single_select', 'options' => [
                    ['value' => 'recibida', 'label' => 'Recibida'], ['value' => 'cerrada', 'label' => 'Cerrada'],
                ]],
                ['name' => 'Costo Total', 'slug' => 'costo_total', 'type' => 'currency', 'options' => null],
            ]],
            ['name' => 'Tecnicos', 'slug' => 'tecnicos', 'fields' => [
                ['name' => 'Nombre', 'slug' => 'nombre', 'type' => 'string', 'options' => null],
                ['name' => 'Especialidad', 'slug' => 'especialidad', 'type' => 'single_select', 'options' => [
                    ['value' => 'mecanica', 'label' => 'Mecanica'], ['value' => 'electrica', 'label' => 'Electrica'],
                ]],
            ]],
            ['name' => 'Refacciones', 'slug' => 'refacciones', 'fields' => [
                ['name' => 'Descripcion', 'slug' => 'descripcion', 'type' => 'string', 'options' => null],
                ['name' => 'Cantidad', 'slug' => 'cantidad', 'type' => 'number', 'options' => null],
                ['name' => 'Costo Unitario', 'slug' => 'costo_unitario', 'type' => 'currency', 'options' => null],
                ['name' => 'Importe', 'slug' => 'importe', 'type' => 'currency', 'options' => null],
            ]],
        ],
        'links' => [
            ['from' => 'sedes', 'to' => 'clientes', 'name' => 'cliente'],
            ['from' => 'ordenes', 'to' => 'sedes', 'name' => 'sede'],
            ['from' => 'ordenes', 'to' => 'tecnicos', 'name' => 'tecnico'],
            ['from' => 'refacciones', 'to' => 'ordenes', 'name' => 'orden'],
        ],
    ];

    return app(AppScaffolder::class)->assemble($base, $spec);
}

it('keeps the dashboard to what fits on a first screen', function () {
    $dashboard = pageBySlug(scaffoldOperation(), 'dashboard');
    $kpis = blockByType($dashboard, 'metric_grid')['items'];

    expect(count($dashboard['blocks']))->toBeLessThanOrEqual(7)
        ->and(count($kpis))->toBeLessThanOrEqual(5);

    // Money leads, and it is the operational core's money: an app tracking work
    // orders wants "how much are we billing", not "how many depots".
    expect($kpis[0]['aggregation'])->toBe('sum')
        ->and($kpis[0]['label'])->toBe('Total Ordenes');

    // One trend, for that same core — not one per object.
    $trends = blocksByType($dashboard, 'sparkline');
    expect($trends)->toHaveCount(1)
        ->and($trends[0]['label'])->toBe('Ordenes en el tiempo');

    // Breakdowns lead with a real lifecycle; a classification only fills what
    // the lifecycle ones left over.
    $donuts = collect(blocksByType($dashboard, 'chart'))
        ->filter(fn (array $b): bool => ($b['chart_type'] ?? null) === 'donut')
        ->values();
    expect($donuts)->toHaveCount(2)
        ->and($donuts[0]['label'])->toBe('Ordenes por estado');
});

it('keeps a line item out of the menu but reachable from its order', function () {
    $manifest = scaffoldOperation();
    $paths = collect($manifest['pages'])->pluck('path');

    // A part used on an order is not a place you navigate to.
    expect($paths)->not->toContain('/refacciones')
        ->and($paths)->toContain('/ordenes', '/clientes');

    // It is still fully usable where it belongs: the order's detail page lists
    // its lines and offers the form that adds one.
    $detail = collect($manifest['pages'])->firstWhere('path', '/ordenes_detail');
    $refacciones = collect($manifest['objects'])->firstWhere('slug', 'refacciones');

    $related = collect($detail['blocks'])->firstWhere('type', 'related_list');
    expect($related['object_id'])->toBe($refacciones['id']);

    $addForm = collect($detail['blocks'])
        ->filter(fn (array $b): bool => ($b['type'] ?? null) === 'modal')
        ->map(fn (array $b): array => $b['blocks'][0] ?? [])
        ->first(fn (array $f): bool => ($f['object_id'] ?? null) === $refacciones['id']);
    expect($addForm)->not->toBeNull();
});

it('leaves a plain child its own page — being a child is not being a line', function () {
    // Sedes belong to Clientes and have no quantity or unit price. "All the
    // depots" is a page someone wants; dropping it would be a loss.
    expect(collect(scaffoldOperation()['pages'])->pluck('path'))->toContain('/sedes');
});

it('names an object in the plural, and its record in the singular', function () {
    // Everything that shows the object name shows MANY records — the list page,
    // its nav entry, the count KPI — while the detail page and the "add"
    // button are about one. Taking the model's word for it gave a table of
    // customers headed "Cliente" and a breadcrumb reading "Cliente › Cliente".
    $base = [
        'schema_version' => '1.0.0', 'id' => 'app_scaffold_pl1', 'slug' => 'pl', 'name' => 'PL', 'version' => 1,
        'objects' => [], 'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
        'settings' => ['default_locale' => 'es-MX', 'default_currency' => 'MXN'],
    ];
    $spec = [
        'objects' => [
            ['name' => 'Cliente', 'slug' => 'clientes', 'fields' => [
                ['name' => 'Razon Social', 'slug' => 'razon_social', 'type' => 'string', 'options' => null],
            ]],
            ['name' => 'Sede', 'slug' => 'sedes', 'fields' => [
                ['name' => 'Nombre', 'slug' => 'nombre', 'type' => 'string', 'options' => null],
            ]],
        ],
        'links' => [['from' => 'sedes', 'to' => 'clientes', 'name' => 'cliente']],
    ];

    $manifest = app(AppScaffolder::class)->assemble($base, $spec);

    expect(collect($manifest['objects'])->pluck('name')->all())->toBe(['Clientes', 'Sedes']);

    $list = pageBySlug($manifest, 'clientes');
    $detail = collect($manifest['pages'])->firstWhere('path', '/clientes_detail');

    expect($list['name'])->toBe('Clientes')
        ->and($detail['name'])->toBe('Cliente')
        // …so the breadcrumb no longer says the same word twice.
        ->and(blockByType($detail, 'breadcrumb')['items'][0]['label'])->toBe('Clientes')
        ->and(blockByType($detail, 'breadcrumb')['items'][1]['label'])->toBe('Cliente');

    // The "add" button still speaks about one record.
    expect(blockByType($list, 'button')['label'])->toBe('Agregar Cliente');
});

it('leaves a name alone when pluralising it would need an accent it cannot infer', function () {
    // "Orden" pluralises to "órdenes" — a moved stress this cannot derive, and
    // a missing accent is a spelling mistake on every screen. A singular
    // heading is the lesser wrong.
    expect(Inflector::plural('Orden de Trabajo', 'es'))->toBe('Orden de Trabajo')
        ->and(Inflector::plural('Refacción', 'es'))->toBe('Refacciones')
        ->and(Inflector::plural('Cliente', 'es'))->toBe('Clientes')
        ->and(Inflector::plural('Clientes', 'es'))->toBe('Clientes');
});
