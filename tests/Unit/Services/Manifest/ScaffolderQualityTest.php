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

function pageBySlug(array $manifest, string $slug): ?array
{
    return collect($manifest['pages'])->firstWhere('slug', $slug);
}

function blocksByType(array $page, string $type): array
{
    return collect($page['blocks'])->where('type', $type)->values()->all();
}

function blockByType(array $page, string $type): ?array
{
    return collect($page['blocks'])->firstWhere('type', $type);
}

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
