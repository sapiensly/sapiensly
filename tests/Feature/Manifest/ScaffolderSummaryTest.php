<?php

use App\Ai\ChatAgent;
use App\Models\User;
use App\Services\Ai\AiDefaults;
use App\Services\AiProviderService;
use App\Services\Manifest\AppScaffolder;
use Laravel\Ai\Ai;

/**
 * What an app says about itself in one line.
 *
 * The description used to BE the build brief — the two thousand characters
 * someone typed to have the app made, headings and bullet lists and all,
 * printed under the app's name in every list. That text is an instruction, not
 * a description: it says what to build, at the length it takes to build it.
 * What a person reading a list of apps needs is the sentence that tells them
 * which one to open. How the app works is answered at length in its user guide
 * and its technical sheet, which is where that question belongs.
 */
function summaryScaffolder(): AppScaffolder
{
    $providers = Mockery::mock(AiProviderService::class);
    $providers->shouldReceive('applyRuntimeConfig')->andReturnNull();
    $providers->shouldReceive('resolveProviderForCatalogModel')->andReturnNull();

    return new AppScaffolder(app(AiDefaults::class), $providers);
}

function summaryBase(?string $description = null): array
{
    $base = [
        'schema_version' => '1.0.0',
        'id' => 'app_summary',
        'slug' => 'sum',
        'name' => 'Sum',
        'version' => 1,
        'objects' => [],
        'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
        'settings' => ['default_locale' => 'en', 'default_currency' => 'USD'],
    ];

    if ($description !== null) {
        $base['description'] = $description;
    }

    return $base;
}

/** A spec as the model returns it, with whatever summary is under test. */
function summarySpec(?string $summary, string $extra = ''): string
{
    $spec = [
        'objects' => [[
            'name' => 'Contracts',
            'slug' => 'contracts',
            'fields' => [['name' => 'Reference', 'slug' => 'reference', 'type' => 'string']],
        ], [
            'name' => 'Payments',
            'slug' => 'payments',
            'fields' => [['name' => 'Amount', 'slug' => 'amount', 'type' => 'currency']],
        ]],
        'links' => [],
    ];

    if ($summary !== null) {
        $spec['summary'] = $summary.$extra;
    }

    return json_encode($spec, JSON_THROW_ON_ERROR);
}

it('replaces the build brief with the one line the model wrote', function () {
    $brief = str_repeat('Build me a leasing system. It has to track everything. ', 40);

    Ai::fakeAgent(ChatAgent::class, [summarySpec('Tracks lease contracts and the payments made against them.')]);

    $manifest = summaryScaffolder()->scaffold(summaryBase($brief), $brief, User::factory()->create());

    expect($manifest['description'])->toBe('Tracks lease contracts and the payments made against them.');
});

it('keeps the description to one line however many the model answered in', function () {
    // Asked for one short sentence, a model returns three often enough that
    // the prompt cannot be the only thing holding this — and it is rendered in
    // a fixed-height card.
    Ai::fakeAgent(ChatAgent::class, [
        summarySpec('Tracks lease contracts.', "\n\nIt also does a second thing.\n\nAnd a third."),
    ]);

    $manifest = summaryScaffolder()->scaffold(summaryBase('anything'), 'anything', User::factory()->create());

    expect($manifest['description'])->toBe('Tracks lease contracts.');
});

it('cuts an over-long summary where a person would end the line', function () {
    $long = 'Tracks lease contracts and their payments. '.str_repeat('It also handles maintenance incidents raised on each property. ', 5);

    Ai::fakeAgent(ChatAgent::class, [summarySpec($long)]);

    $manifest = summaryScaffolder()->scaffold(summaryBase('anything'), 'anything', User::factory()->create());

    expect(mb_strlen($manifest['description']))->toBeLessThanOrEqual(180)
        ->and($manifest['description'])->toEndWith('.');
});

it('falls back to naming the objects when the model gave no summary', function () {
    $brief = str_repeat('A long brief that is plainly an instruction and not a description. ', 10);

    Ai::fakeAgent(ChatAgent::class, [summarySpec(null)]);

    $manifest = summaryScaffolder()->scaffold(summaryBase($brief), $brief, User::factory()->create());

    // Mechanical, and deliberately so: it is the floor under a missing
    // summary, and still beats printing the brief.
    expect($manifest['description'])->toBe('Keeps track of Contracts and Payments.');
});

it('leaves a description alone when it is already short enough to be one', function () {
    // The in-app builder assembles over a manifest whose description may have
    // been written by hand. "Keeps track of A and B." is not an improvement.
    Ai::fakeAgent(ChatAgent::class, [summarySpec(null)]);

    $manifest = summaryScaffolder()->scaffold(
        summaryBase('Our leasing desk, end to end.'),
        'anything',
        User::factory()->create(),
    );

    expect($manifest['description'])->toBe('Our leasing desk, end to end.');
});

it('gives a list page the filters its object affords, wired to every view', function () {
    // A generated list had a search box and sortable headings and no way to ask
    // "only the ones still open" — so an app built from a brief that said, in
    // as many words, "show me which ones will miss the promised date" could not
    // answer it by any route.
    Ai::fakeAgent(ChatAgent::class, [json_encode([
        'summary' => 'Órdenes de un taller.',
        'objects' => [[
            'name' => 'Órdenes',
            'slug' => 'ordenes',
            'fields' => [
                ['name' => 'Folio', 'slug' => 'folio', 'type' => 'string'],
                ['name' => 'Fecha prometida de entrega', 'slug' => 'fecha_prometida', 'type' => 'date'],
                ['name' => 'Estado', 'slug' => 'estado', 'type' => 'single_select', 'options' => [
                    ['value' => 'recibida', 'label' => 'Recibida'],
                    ['value' => 'entregada', 'label' => 'Entregada'],
                ]],
            ],
        ]],
        'links' => [],
    ], JSON_THROW_ON_ERROR)]);

    $base = summaryBase();
    $base['settings']['default_locale'] = 'es-MX';
    $manifest = summaryScaffolder()->scaffold($base, 'Taller.', User::factory()->create());

    $page = collect($manifest['pages'])->firstWhere('path', '/ordenes');
    $bar = collect($page['blocks'])->firstWhere('type', 'filter_bar');

    expect($bar)->not->toBeNull()
        ->and(collect($bar['controls'])->pluck('param')->all())->toBe(['status', 'range']);

    // No default window. A list page IS the object, and opening it silently
    // scoped to thirty days hides records with nothing on screen to say so.
    expect(collect($bar['controls'])->firstWhere('param', 'range')['default'])->toBe('all');

    // Every view answers to the bar. These tabs are the same records drawn
    // several ways, and a filter that survived "Lista" but not "Tablero" would
    // read as the board having different data.
    $tabs = collect($page['blocks'])->firstWhere('type', 'tabs');
    $filtered = collect($tabs['tabs'])
        ->flatMap(fn (array $tab): array => $tab['blocks'])
        ->map(fn (array $b): mixed => $b['data_source']['filter'] ?? null);

    expect($filtered)->toHaveCount(3)
        ->and($filtered->filter()->count())->toBe(3);
});

it('leaves out a filter the object cannot answer', function () {
    // Only controls at least one block listens to. An object with no status and
    // no date gets no bar rather than a row of dead controls.
    Ai::fakeAgent(ChatAgent::class, [json_encode([
        'summary' => 'Un directorio.',
        'objects' => [[
            'name' => 'Contactos',
            'slug' => 'contactos',
            'fields' => [['name' => 'Nombre', 'slug' => 'nombre', 'type' => 'string']],
        ]],
        'links' => [],
    ], JSON_THROW_ON_ERROR)]);

    $manifest = summaryScaffolder()->scaffold(summaryBase(), 'Directorio.', User::factory()->create());
    $page = collect($manifest['pages'])->firstWhere('path', '/contactos');

    expect(collect($page['blocks'])->firstWhere('type', 'filter_bar'))->toBeNull();
});

it('never puts the same figure on the dashboard twice', function () {
    // A count does not read its field — it counts rows — so two count measures
    // on one object differing only in which field they named came out as two
    // tiles with the same label and the same number. A live workshop dashboard
    // opened with "Órdenes de servicio 14" twice, side by side.
    Ai::fakeAgent(ChatAgent::class, [json_encode([
        'summary' => 'Órdenes de un taller.',
        'objects' => [[
            'name' => 'Órdenes',
            'slug' => 'ordenes',
            'fields' => [
                ['name' => 'Folio', 'slug' => 'folio', 'type' => 'string'],
                ['name' => 'Total', 'slug' => 'total', 'type' => 'currency'],
                ['name' => 'Estado', 'slug' => 'estado', 'type' => 'single_select', 'options' => [
                    ['value' => 'abierta', 'label' => 'Abierta'],
                ]],
            ],
        ]],
        'links' => [],
        'focus' => [
            'objects' => ['ordenes'],
            'measures' => [
                ['object' => 'ordenes', 'field' => 'folio', 'aggregation' => 'count'],
                ['object' => 'ordenes', 'field' => 'total', 'aggregation' => 'sum'],
                ['object' => 'ordenes', 'field' => 'estado', 'aggregation' => 'count'],
            ],
        ],
    ], JSON_THROW_ON_ERROR)]);

    $manifest = summaryScaffolder()->scaffold(summaryBase(), 'Taller.', User::factory()->create());

    $dashboard = collect($manifest['pages'])->firstWhere('path', '/');
    $grid = collect($dashboard['blocks'])->firstWhere('type', 'metric_grid');

    $signatures = collect($grid['items'])->map(fn (array $i): string => ($i['query']['object_id'] ?? '')
        .'|'.($i['aggregation'] ?? '')
        .'|'.($i['field_id'] ?? ''));

    expect($signatures->duplicates())->toBeEmpty()
        ->and(collect($grid['items'])->pluck('label')->duplicates())->toBeEmpty();
});

it('lets somebody act on the record whose page they are looking at', function () {
    // A detail page showed the record and offered nothing: changing an order's
    // status meant going back to the list to find the row, and no generated app
    // could delete a record at all — a typo lived for ever.
    Ai::fakeAgent(ChatAgent::class, [json_encode([
        'summary' => 'Clientes y sus vehículos.',
        'objects' => [
            ['name' => 'Clientes', 'slug' => 'clientes', 'fields' => [
                ['name' => 'Nombre', 'slug' => 'nombre', 'type' => 'string'],
                ['name' => 'Teléfono', 'slug' => 'telefono', 'type' => 'phone'],
            ]],
            ['name' => 'Vehículos', 'slug' => 'vehiculos', 'fields' => [
                ['name' => 'Placas', 'slug' => 'placas', 'type' => 'string'],
            ]],
        ],
        'links' => [['from' => 'vehiculos', 'to' => 'clientes', 'name' => 'cliente']],
    ], JSON_THROW_ON_ERROR)]);

    $base = summaryBase();
    $base['settings']['default_locale'] = 'es-MX';
    $manifest = summaryScaffolder()->scaffold($base, 'Taller.', User::factory()->create());

    $detail = collect($manifest['pages'])->first(
        fn (array $p): bool => str_contains((string) $p['path'], 'clientes_detail'),
    );
    expect($detail)->not->toBeNull();

    $buttons = collect($detail['blocks'])->where('type', 'button');
    $edit = $buttons->firstWhere('label', 'Editar');
    $delete = $buttons->firstWhere('label', 'Eliminar');

    // The edit form edits the record being SHOWN, so it takes the id from the
    // page rather than from a row that is not there.
    $modal = collect($detail['blocks'])->firstWhere('type', 'modal');
    expect($edit)->not->toBeNull()
        ->and($modal['blocks'][0]['record_id_expression'])->toBe('{{params.id}}');

    expect($delete)->not->toBeNull()
        ->and($delete['variant'])->toBe('danger')
        // Gated on the roles the scaffold actually grants delete to. An action
        // COLUMN carries no visibility, which is the other reason delete lives
        // on this page and not as a row action in the list.
        ->and($delete['visibility']['roles'])->toBe(['admin'])
        ->and($delete['confirm']['title'])->toBe('¿Eliminar Cliente?');

    $kinds = collect($delete['on_click'])->pluck('type')->all();
    expect($kinds)->toBe(['delete_record', 'show_toast', 'navigate'])
        // Staying would leave the page asking the server for a record that is
        // no longer there.
        ->and(collect($delete['on_click'])->firstWhere('type', 'navigate')['to'])->toBe('/clientes');
});

it('gives a child row the edit and delete it has nowhere else to put', function () {
    // A child object has no page of its own, so the related list on the parent
    // IS its screen. Without row actions a line item could be added and then
    // never corrected or removed — a mistyped quantity became permanent.
    Ai::fakeAgent(ChatAgent::class, [json_encode([
        'summary' => 'Órdenes y sus refacciones.',
        'objects' => [
            ['name' => 'Órdenes', 'slug' => 'ordenes', 'fields' => [
                ['name' => 'Folio', 'slug' => 'folio', 'type' => 'string'],
            ]],
            ['name' => 'Refacciones', 'slug' => 'refacciones', 'fields' => [
                ['name' => 'Pieza', 'slug' => 'pieza', 'type' => 'string'],
                ['name' => 'Cantidad', 'slug' => 'cantidad', 'type' => 'number'],
            ]],
        ],
        'links' => [['from' => 'refacciones', 'to' => 'ordenes', 'name' => 'orden']],
    ], JSON_THROW_ON_ERROR)]);

    $base = summaryBase();
    $base['settings']['default_locale'] = 'es-MX';
    $manifest = summaryScaffolder()->scaffold($base, 'Taller.', User::factory()->create());

    $detail = collect($manifest['pages'])->first(
        fn (array $p): bool => str_contains((string) $p['path'], 'ordenes_detail'),
    );
    $list = collect($detail['blocks'])->firstWhere('type', 'related_list');

    $actions = collect($list['columns'])->where('type', 'action');
    expect($actions->pluck('label')->all())->toBe(['Editar', 'Eliminar']);

    // Edit opens a modal carrying the clicked row; delete asks first and names
    // what it is about to remove.
    $delete = $actions->firstWhere('label', 'Eliminar');
    expect($delete['confirm']['title'])->toBe('¿Eliminar Refacción?')
        // Same gate the detail page's Delete carries — an action column takes
        // `visibility` now, so a row button and a page button agree about who
        // may press them.
        ->and($delete['visibility']['roles'])->toBe(['admin'])
        ->and(collect($delete['on_click'])->pluck('type')->all())->toBe(['delete_record', 'refresh'])
        ->and(collect($delete['on_click'])->first()['record_id_expression'])->toBe('{{row.id}}');

    // The edit form edits the row that was clicked, not the page's record.
    $editModal = collect($detail['blocks'])
        ->where('type', 'modal')
        ->pluck('blocks.0')
        ->first(fn (?array $f): bool => ($f['mode'] ?? null) === 'edit'
            && $f['object_id'] === collect($manifest['objects'])->firstWhere('slug', 'refacciones')['id']);

    expect($editModal)->not->toBeNull()
        ->and($editModal['record_id_expression'])->toBe('{{params.record_id}}');
});

it('gives the dashboard a window, and every figure on it answers', function () {
    // A generated dashboard could only ever say one thing: "Órdenes 14" meant
    // since the beginning of time. The brief it was built from asks how much is
    // being billed — a question with a period in it.
    Ai::fakeAgent(ChatAgent::class, [json_encode([
        'summary' => 'Órdenes y clientes.',
        'objects' => [
            ['name' => 'Órdenes', 'slug' => 'ordenes', 'fields' => [
                ['name' => 'Folio', 'slug' => 'folio', 'type' => 'string'],
                ['name' => 'Fecha prometida de entrega', 'slug' => 'fecha_prometida', 'type' => 'date'],
                ['name' => 'Total', 'slug' => 'total', 'type' => 'currency'],
                ['name' => 'Estado', 'slug' => 'estado', 'type' => 'single_select', 'options' => [
                    ['value' => 'abierta', 'label' => 'Abierta'],
                    ['value' => 'cerrada', 'label' => 'Cerrada'],
                ]],
            ]],
            ['name' => 'Clientes', 'slug' => 'clientes', 'fields' => [
                ['name' => 'Nombre', 'slug' => 'nombre', 'type' => 'string'],
            ]],
        ],
        'links' => [],
    ], JSON_THROW_ON_ERROR)]);

    $base = summaryBase();
    $base['settings']['default_locale'] = 'es-MX';
    $manifest = summaryScaffolder()->scaffold($base, 'Taller.', User::factory()->create());

    $dashboard = collect($manifest['pages'])->firstWhere('path', '/');
    $flat = collect($dashboard['blocks'])
        ->flatMap(fn (array $b): array => $b['type'] === 'container' ? $b['blocks'] : [$b]);

    $bar = $flat->firstWhere('type', 'filter_bar');
    expect($bar)->not->toBeNull()
        // Opens exactly as the board read before: a default of 30 days would
        // silently turn 14 into 3 with nothing on screen to say why.
        ->and($bar['controls'][0])->toMatchArray(['param' => 'range', 'default' => 'all']);

    $ordenes = collect($manifest['objects'])->firstWhere('slug', 'ordenes');
    $clientes = collect($manifest['objects'])->firstWhere('slug', 'clientes');
    $prometida = collect($ordenes['fields'])->firstWhere('slug', 'fecha_prometida')['id'];

    // Each block is scoped by ITS OWN object's date: a dashboard aggregates
    // across objects and each keeps time differently.
    $grid = $flat->firstWhere('type', 'metric_grid');
    $byObject = collect($grid['items'])->mapWithKeys(fn (array $i): array => [
        $i['query']['object_id'] => $i['query']['filter']['field_id'] ?? null,
    ]);

    expect($byObject[$ordenes['id']])->toBe($prometida)
        // No date it looks forward to, so the row's own created-at — which
        // every record has, so no figure sits outside the window silently.
        ->and($byObject[$clientes['id']])->toBe('sys_created_at');

    $chart = $flat->firstWhere('type', 'chart');
    expect($chart['data_source']['filter']['value_expression'])
        ->toBe("{{range_start(default(params.range, 'all'))}}");
});

it('leaves the bar off a dashboard nothing would listen to', function () {
    // Same rule the list page follows: never a control that does nothing.
    Ai::fakeAgent(ChatAgent::class, [summarySpec('Dos objetos sin nada que graficar.')]);

    $manifest = summaryScaffolder()->scaffold(summaryBase(), 'X.', User::factory()->create());
    $dashboard = collect($manifest['pages'])->firstWhere('path', '/');

    if ($dashboard === null) {
        expect(true)->toBeTrue(); // No dashboard at all is a valid outcome here.

        return;
    }

    $grid = collect($dashboard['blocks'])->firstWhere('type', 'metric_grid');
    $wired = $grid !== null && collect($grid['items'])->contains(
        fn (array $i): bool => isset($i['query']['filter']),
    );

    expect(collect($dashboard['blocks'])->contains(fn (array $b): bool => $b['type'] === 'filter_bar'))
        ->toBe($wired);
});

it('gives a childless object a page of its own, so it can be removed too', function () {
    // Detail pages used to be built for parents only, and that quietly decided
    // who could be deleted: Delete is a button on that page — the only control
    // the schema lets us gate by role — so an object with nothing hanging off
    // it could be created and then never removed by any route in its own app.
    Ai::fakeAgent(ChatAgent::class, [json_encode([
        'summary' => 'Órdenes, sus refacciones, y los mecánicos.',
        'objects' => [
            ['name' => 'Órdenes', 'slug' => 'ordenes', 'fields' => [
                ['name' => 'Folio', 'slug' => 'folio', 'type' => 'string'],
            ]],
            // A real line item: quantity + unit price, hanging off an order.
            ['name' => 'Refacciones', 'slug' => 'refacciones', 'fields' => [
                ['name' => 'Pieza', 'slug' => 'pieza', 'type' => 'string'],
                ['name' => 'Cantidad', 'slug' => 'cantidad', 'type' => 'number'],
                ['name' => 'Precio unitario', 'slug' => 'precio_unitario', 'type' => 'currency'],
            ]],
            // Nothing points at it and it points at nothing.
            ['name' => 'Mecánicos', 'slug' => 'mecanicos', 'fields' => [
                ['name' => 'Nombre', 'slug' => 'nombre', 'type' => 'string'],
                ['name' => 'Especialidad', 'slug' => 'especialidad', 'type' => 'string'],
            ]],
        ],
        'links' => [['from' => 'refacciones', 'to' => 'ordenes', 'name' => 'orden']],
    ], JSON_THROW_ON_ERROR)]);

    $base = summaryBase();
    $base['settings']['default_locale'] = 'es-MX';
    $manifest = summaryScaffolder()->scaffold($base, 'Taller.', User::factory()->create());

    $paths = collect($manifest['pages'])->pluck('path');

    // The lone object gets the same page the parent does.
    expect($paths)->toContain('/mecanicos_detail')
        ->and($paths)->toContain('/ordenes_detail')
        // A line item still does not: it has no list either, and it is edited
        // and removed from the related list on the order it belongs to.
        ->and($paths)->not->toContain('/refacciones_detail');

    $lone = collect($manifest['pages'])->firstWhere('path', '/mecanicos_detail');
    $delete = collect($lone['blocks'])->where('type', 'button')->firstWhere('label', 'Eliminar');

    expect($delete)->not->toBeNull()
        ->and($delete['visibility']['roles'])->toBe(['admin'])
        ->and(collect($delete['on_click'])->first()['record_id_expression'])->toBe('{{params.id}}');

    // And the list can reach it.
    $list = collect($manifest['pages'])->firstWhere('path', '/mecanicos');
    $table = collect($list['blocks'])
        ->flatMap(fn (array $b): array => $b['type'] === 'tabs'
            ? collect($b['tabs'])->flatMap(fn (array $t): array => $t['blocks'])->all()
            : [$b])
        ->firstWhere('type', 'table');

    expect(collect($table['columns'])->where('type', 'action')->pluck('label'))
        ->toContain('Abrir');
});
