<?php

use App\Services\Manifest\AppScaffolder;

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
        'permissions' => ['roles' => [['id' => 'rol_a', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
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

function pageBySlug(array $manifest, string $slug): array
{
    return collect($manifest['pages'])->firstWhere('slug', $slug);
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
