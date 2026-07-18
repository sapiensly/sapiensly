<?php

use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Build\ScaffoldAppTool;
use App\Models\App;
use App\Models\Record;
use App\Models\User;
use App\Services\Ai\AiDefaults;
use App\Services\AiProviderService;
use App\Services\Manifest\AppScaffolder;
use App\Services\Manifest\ManifestValidator;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
});

/**
 * Stub the model-driven spec extraction with a fixed spec, but run it through
 * the REAL deterministic assembler so the test exercises the tool's persistence
 * path against a genuinely-assembled (and validator-checked) manifest.
 *
 * @param  array<int, array<string, mixed>>  $objects
 * @param  array<int, array<string, mixed>>  $links
 */
function fakeScaffold(array $objects, array $links = []): void
{
    test()->mock(AppScaffolder::class)
        ->shouldReceive('scaffold')
        ->once()
        ->andReturnUsing(function (array $base) use ($objects, $links): array {
            $real = new AppScaffolder(app(AiDefaults::class), app(AiProviderService::class));

            return $real->assemble($base, ['objects' => $objects, 'links' => $links]);
        });
}

it('scaffold_app creates a populated app with a CRUD page per object', function () {
    fakeScaffold([
        ['name' => 'Ideas', 'slug' => 'ideas', 'fields' => [
            ['name' => 'Title', 'slug' => 'title', 'type' => 'string', 'options' => null],
            ['name' => 'Status', 'slug' => 'status', 'type' => 'single_select', 'options' => [
                ['value' => 'backlog', 'label' => 'Backlog'],
                ['value' => 'ready', 'label' => 'Ready'],
            ]],
        ]],
        ['name' => 'Drafts', 'slug' => 'drafts', 'fields' => [
            ['name' => 'Title', 'slug' => 'title', 'type' => 'string', 'options' => null],
        ]],
    ]);

    SapiensServer::actingAs($this->user)
        ->tool(ScaffoldAppTool::class, [
            'name' => 'Content Engine',
            'description' => 'Track content ideas through drafts to publication.',
        ])
        ->assertOk()
        ->assertSee('content_engine')
        ->assertSee('version_number');

    $app = App::where('user_id', $this->user->id)->where('slug', 'content_engine')->first();
    expect($app)->not->toBeNull();
    expect($app->versions()->count())->toBe(1);

    $manifest = $app->versions()->first()->manifest;
    expect($manifest['objects'])->toHaveCount(2);
    // A dashboard landing page plus one CRUD page per object.
    expect($manifest['pages'])->toHaveCount(3);
    expect(collect($manifest['pages'])->pluck('path'))->toContain('/', '/ideas', '/drafts');

    // The dashboard shows a KPI per object and a status distribution chart.
    $dashboard = collect($manifest['pages'])->firstWhere('path', '/');
    $metricGrid = collect($dashboard['blocks'])->firstWhere('type', 'metric_grid');
    expect($metricGrid['items'])->toHaveCount(2);
    expect(collect($dashboard['blocks'])->pluck('type'))->toContain('chart');

    // The status-bearing object's page gets a kanban board; the other doesn't.
    $ideas = collect($manifest['pages'])->firstWhere('path', '/ideas');
    $drafts = collect($manifest['pages'])->firstWhere('path', '/drafts');
    expect(collect($ideas['blocks'])->pluck('type'))->toContain('kanban', 'table');
    expect(collect($drafts['blocks'])->pluck('type'))->not->toContain('kanban');

    expect(app(ManifestValidator::class)->validate($manifest)->valid)->toBeTrue();
});

it('scaffold_app links objects with a belongs-to relation pair', function () {
    fakeScaffold([
        ['name' => 'Ideas', 'slug' => 'ideas', 'fields' => [
            ['name' => 'Title', 'slug' => 'title', 'type' => 'string', 'options' => null],
        ]],
        ['name' => 'Drafts', 'slug' => 'drafts', 'fields' => [
            ['name' => 'Title', 'slug' => 'title', 'type' => 'string', 'options' => null],
        ]],
    ], [
        ['from' => 'drafts', 'to' => 'ideas', 'name' => 'idea'],
    ]);

    SapiensServer::actingAs($this->user)
        ->tool(ScaffoldAppTool::class, [
            'name' => 'Content Engine',
            'description' => 'Ideas become drafts.',
        ])
        ->assertOk();

    $manifest = App::where('slug', 'content_engine')->first()->versions()->first()->manifest;
    $ideas = collect($manifest['objects'])->firstWhere('slug', 'ideas');
    $drafts = collect($manifest['objects'])->firstWhere('slug', 'drafts');

    // Drafts gets the many_to_one (belongs to one idea); Ideas gets the inverse.
    $belongsTo = collect($drafts['fields'])->firstWhere('type', 'relation');
    $hasMany = collect($ideas['fields'])->firstWhere('type', 'relation');
    expect($belongsTo['cardinality'])->toBe('many_to_one');
    expect($belongsTo['target_object_id'])->toBe($ideas['id']);
    expect($hasMany['cardinality'])->toBe('one_to_many');
    expect($hasMany['target_object_id'])->toBe($drafts['id']);
    // Inverses point at each other.
    expect($belongsTo['inverse_field_id'])->toBe($hasMany['id']);
    expect($hasMany['inverse_field_id'])->toBe($belongsTo['id']);

    // The picker shows up on the Drafts page (table column).
    $draftsPage = collect($manifest['pages'])->firstWhere('path', '/drafts');
    $table = collect($draftsPage['blocks'])->firstWhere('type', 'table');
    expect(collect($table['columns'])->pluck('field_id'))->toContain($belongsTo['id']);

    // Ideas gets a child-count rollup, shown on its table but not its create form.
    $rollup = collect($ideas['fields'])->firstWhere('type', 'rollup');
    expect($rollup['aggregator'])->toBe('count');
    expect($rollup['via_relation_field_id'])->toBe($hasMany['id']);
    $ideasPage = collect($manifest['pages'])->firstWhere('path', '/ideas');
    $ideasTable = collect($ideasPage['blocks'])->firstWhere('type', 'table');
    $ideasModal = collect($ideasPage['blocks'])->firstWhere('type', 'modal');
    $ideasForm = collect($ideasModal['blocks'])->firstWhere('type', 'form');
    expect(collect($ideasTable['columns'])->pluck('field_id'))->toContain($rollup['id']);
    expect(collect($ideasForm['fields'])->pluck('field_id'))->not->toContain($rollup['id']);

    expect(app(ManifestValidator::class)->validate($manifest)->valid)->toBeTrue();
});

it('scaffold_app derives a unique slug from the name when omitted', function () {
    App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'slug' => 'content_engine',
    ]);

    fakeScaffold([
        ['name' => 'Ideas', 'slug' => 'ideas', 'fields' => [
            ['name' => 'Title', 'slug' => 'title', 'type' => 'string', 'options' => null],
        ]],
    ]);

    SapiensServer::actingAs($this->user)
        ->tool(ScaffoldAppTool::class, [
            'name' => 'Content Engine',
            'description' => 'Another content app.',
        ])
        ->assertOk()
        ->assertSee('content_engine_2');
});

it('scaffold_app seeds initial records, matching objects, fields and options tolerantly', function () {
    fakeScaffold([
        ['name' => 'Tareas', 'slug' => 'tareas', 'fields' => [
            ['name' => 'Título', 'slug' => 'titulo', 'type' => 'string', 'options' => null],
            ['name' => 'Fecha de inicio', 'slug' => 'fecha_inicio', 'type' => 'date', 'options' => null],
            ['name' => 'Fecha fin', 'slug' => 'fecha_fin', 'type' => 'date', 'options' => null],
            ['name' => 'Estado', 'slug' => 'estado', 'type' => 'single_select', 'options' => [
                ['value' => 'pendiente', 'label' => 'Pendiente'],
                ['value' => 'en_curso', 'label' => 'En curso'],
            ]],
        ]],
    ]);

    SapiensServer::actingAs($this->user)
        ->tool(ScaffoldAppTool::class, [
            'name' => 'Growth Tracker',
            'description' => 'Plan de 90 días.',
            'seed_records' => [
                [
                    // Object referenced by NAME (not the generated slug).
                    'object' => 'Tareas',
                    'records' => [
                        [
                            // Field by name, select by label — both must snap on.
                            'Título' => 'Publicar hilo build-in-public',
                            'fecha_inicio' => '2026-07-01',
                            'Fecha fin' => '2026-07-07',
                            'Estado' => 'En curso',
                        ],
                        [
                            'titulo' => 'Draft del essay',
                            'fecha_inicio' => '2026-07-08',
                            'fecha_fin' => '2026-07-14',
                            'estado' => 'pendiente',
                        ],
                        // Bad row: invalid option — reported, not fatal.
                        ['titulo' => 'Fila mala', 'estado' => 'nope'],
                    ],
                ],
            ],
        ])
        ->assertOk()
        ->assertSee('Row 2');

    $app = App::where('user_id', $this->user->id)->where('slug', 'growth_tracker')->first();
    expect($app)->not->toBeNull();

    $records = Record::where('app_id', $app->id)->orderBy('created_at')->get();
    expect($records)->toHaveCount(2);
    expect($records[0]->data['titulo'])->toBe('Publicar hilo build-in-public');
    expect($records[0]->data['estado'])->toBe('en_curso');
    expect($records[0]->data['fecha_fin'])->toBe('2026-07-07');
    expect($records[1]->data['estado'])->toBe('pendiente');
});

it('scaffold_app matches a seed object across the singular/plural boundary', function () {
    // The model names objects however it likes (often singular), while the seed
    // labels come from the prompt (often plural) — they must still match.
    fakeScaffold([
        ['name' => 'Company', 'slug' => 'company', 'fields' => [
            ['name' => 'Name', 'slug' => 'name', 'type' => 'string', 'options' => null],
        ]],
        ['name' => 'Proveedor', 'slug' => 'proveedor', 'fields' => [
            ['name' => 'Nombre', 'slug' => 'nombre', 'type' => 'string', 'options' => null],
        ]],
    ]);

    SapiensServer::actingAs($this->user)
        ->tool(ScaffoldAppTool::class, [
            'name' => 'Vendor Book',
            'description' => 'x',
            'seed_records' => [
                // Plural English seed label vs singular object (Str::singular).
                ['object' => 'Companies', 'records' => [['name' => 'Acme']]],
                // Plural Spanish seed label vs singular object (Inflector es).
                ['object' => 'Proveedores', 'records' => [['nombre' => 'Globex']]],
            ],
        ])
        ->assertOk();

    $app = App::where('user_id', $this->user->id)->where('slug', 'vendor_book')->first();
    $data = Record::where('app_id', $app->id)->orderBy('created_at')->get()->map(fn ($r) => $r->data);

    expect($data)->toHaveCount(2);
    expect($data->pluck('name')->filter()->all())->toContain('Acme');
    expect($data->pluck('nombre')->filter()->all())->toContain('Globex');
});

it('scaffold_app reports an unmatched seed object without failing the build', function () {
    fakeScaffold([
        ['name' => 'Ideas', 'slug' => 'ideas', 'fields' => [
            ['name' => 'Title', 'slug' => 'title', 'type' => 'string', 'options' => null],
        ]],
    ]);

    SapiensServer::actingAs($this->user)
        ->tool(ScaffoldAppTool::class, [
            'name' => 'Seedless',
            'description' => 'x',
            'seed_records' => [
                ['object' => 'no_such_thing', 'records' => [['title' => 'x']]],
            ],
        ])
        ->assertOk()
        ->assertSee('No scaffolded object matched');

    $app = App::where('user_id', $this->user->id)->where('slug', 'seedless')->first();
    expect($app)->not->toBeNull();
    expect(Record::where('app_id', $app->id)->count())->toBe(0);
});

it('scaffold_app rejects an explicit duplicate slug without creating an app', function () {
    App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'slug' => 'taken',
    ]);

    SapiensServer::actingAs($this->user)
        ->tool(ScaffoldAppTool::class, [
            'name' => 'Dup',
            'slug' => 'taken',
            'description' => 'x',
        ])
        ->assertHasErrors();

    expect(App::where('name', 'Dup')->exists())->toBeFalse();
});

it('assembles a valid manifest with the create-modal wiring', function () {
    $base = [
        'schema_version' => '1.0.0',
        'id' => 'app_'.strtolower((string) Str::ulid()),
        'slug' => 'demo',
        'name' => 'Demo',
        'version' => 1,
        'objects' => [],
        'pages' => [],
        'permissions' => ['roles' => [
            ['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true],
        ]],
        'settings' => ['default_currency' => 'MXN'],
    ];

    $manifest = app(AppScaffolder::class)->assemble($base, ['objects' => [
        ['name' => 'Tasks', 'slug' => 'tasks', 'fields' => [
            ['name' => 'Title', 'slug' => 'title', 'type' => 'string', 'options' => null],
        ]],
    ]]);

    expect(app(ManifestValidator::class)->validate($manifest)->valid)->toBeTrue();

    $page = collect($manifest['pages'])->firstWhere('path', '/tasks');
    $blockTypes = collect($page['blocks'])->pluck('type')->all();
    expect($blockTypes)->toContain('heading', 'modal', 'button', 'table');

    // The button opens the modal that actually exists on the page.
    $button = collect($page['blocks'])->firstWhere('type', 'button');
    $modal = collect($page['blocks'])->firstWhere('type', 'modal');
    expect($button['on_click'][0]['modal_block_id'])->toBe($modal['id']);
});
