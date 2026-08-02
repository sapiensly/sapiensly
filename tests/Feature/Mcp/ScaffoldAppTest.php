<?php

use App\Ai\ChatAgent;
use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Build\ScaffoldAppTool;
use App\Models\AiUsageEvent;
use App\Models\App;
use App\Models\Record;
use App\Models\User;
use App\Services\Ai\AiDefaults;
use App\Services\AiProviderService;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\AppScaffolder;
use App\Services\Manifest\ManifestValidator;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;

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

/**
 * Every block type on a page, however deeply nested.
 *
 * @param  array<string, mixed>  $page
 * @return list<string>
 */
function blockTypesAnywhere(array $page): array
{
    $walk = function (array $blocks) use (&$walk): array {
        $types = [];
        foreach ($blocks as $block) {
            $types[] = (string) ($block['type'] ?? '');
            foreach (['blocks', 'left_blocks', 'right_blocks'] as $key) {
                $types = [...$types, ...$walk($block[$key] ?? [])];
            }
            foreach (['tabs', 'sections'] as $key) {
                foreach ($block[$key] ?? [] as $child) {
                    $types = [...$types, ...$walk($child['blocks'] ?? [])];
                }
            }
        }

        return $types;
    };

    return $walk($page['blocks'] ?? []);
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
    // Anywhere: the dashboard pairs its charts inside row containers now, so a
    // top-level scan sees the container and not what is in it.
    expect(blockTypesAnywhere($dashboard))->toContain('chart');

    // The status-bearing object's page gets a kanban board; the other doesn't.
    // Addressed through the whole page rather than its top level: a page with
    // more than one way to look at the same rows puts them in tabs, so the
    // board sits beside the list instead of stacked under it.
    $ideas = collect($manifest['pages'])->firstWhere('path', '/ideas');
    $drafts = collect($manifest['pages'])->firstWhere('path', '/drafts');
    expect(blockTypesAnywhere($ideas))->toContain('kanban', 'table');
    expect(blockTypesAnywhere($drafts))->not->toContain('kanban');

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

it('scaffold_app hands the caller every downgrade it applied', function () {
    // Runs the REAL scaffolder with only the model faked, so the coercion is
    // produced by the same normalizer the product uses. A downgrade the caller
    // never sees is a field they believe they asked for and did not get.
    Ai::fakeAgent(ChatAgent::class, [
        '{"objects":[{"name":"Tickets","slug":"tickets","fields":['
        .'{"name":"Asunto","slug":"asunto","type":"string"},'
        .'{"name":"Adjunto","slug":"adjunto","type":"file"}]}],"links":[]}',
    ]);

    SapiensServer::actingAs($this->user)
        ->tool(ScaffoldAppTool::class, [
            'name' => 'Soporte',
            'description' => 'Mesa de ayuda con tickets.',
        ])
        ->assertOk()
        ->assertSee('warnings')
        ->assertSee('is not available here');
});

it('scaffold_app keeps an email field the model asked for', function () {
    Ai::fakeAgent(ChatAgent::class, [
        '{"objects":[{"name":"Tickets","slug":"tickets","fields":['
        .'{"name":"Asunto","slug":"asunto","type":"string"},'
        .'{"name":"Correo","slug":"correo","type":"email"}]}],"links":[]}',
    ]);

    SapiensServer::actingAs($this->user)
        ->tool(ScaffoldAppTool::class, [
            'name' => 'Soporte Correo',
            'description' => 'Mesa de ayuda con el correo del solicitante.',
        ])
        ->assertOk();

    $app = App::where('user_id', $this->user->id)->where('slug', 'soporte_correo')->firstOrFail();
    $correo = collect($app->versions()->first()->manifest['objects'][0]['fields'])
        ->firstWhere('slug', 'correo');

    // Not a plain string: the type carries server-side validation and renders an
    // email input. Storing a requester's address as free text is how a help desk
    // ends up unable to reply to its own tickets.
    expect($correo['type'])->toBe('email');
});

it('bills the scaffold call to the app it created', function () {
    // Creating an app was the one billable model call the ledger never saw:
    // get_build_cost answered $0 for every scaffolded app, and an org whose AI
    // budget was spent could still scaffold, because nothing counted it.
    Ai::fakeAgent(ChatAgent::class, [
        '{"objects":[{"name":"Tickets","slug":"tickets","fields":['
        .'{"name":"Asunto","slug":"asunto","type":"string"}]}],"links":[]}',
    ]);

    SapiensServer::actingAs($this->user)
        ->tool(ScaffoldAppTool::class, [
            'name' => 'Contabilizado',
            'description' => 'Mesa de ayuda con tickets.',
        ])
        ->assertOk();

    $app = App::where('user_id', $this->user->id)->where('slug', 'contabilizado')->firstOrFail();
    $event = AiUsageEvent::where('app_id', $app->id)->where('module', 'scaffold')->first();

    expect($event)->not->toBeNull()
        ->and($event->organization_id)->toBe($this->user->organization_id)
        ->and($event->model)->toBe(app(AiDefaults::class)->model('flows'));
});

it('scaffold_app leaves no app behind when the model is unreachable', function () {
    Ai::fakeAgent(ChatAgent::class, [
        fn () => throw new RuntimeException('no API key configured'),
    ]);

    SapiensServer::actingAs($this->user)
        ->tool(ScaffoldAppTool::class, [
            'name' => 'Fantasma',
            'description' => 'Mesa de ayuda con tickets.',
        ])
        ->assertHasErrors();

    // Not an empty app the user has to discover is empty.
    expect(App::where('user_id', $this->user->id)->where('slug', 'fantasma')->exists())->toBeFalse();
});

it('scaffold_app names the automation the schema affords, without authoring it', function () {
    Ai::fakeAgent(ChatAgent::class, [
        '{"objects":[{"name":"Tickets","slug":"tickets","fields":['
        .'{"name":"Asunto","slug":"asunto","type":"string"},'
        .'{"name":"Estado","slug":"estado","type":"single_select","options":['
        .'{"value":"nuevo","label":"Nuevo"},{"value":"resuelto","label":"Resuelto"}]},'
        .'{"name":"Vence","slug":"vence","type":"date"}]}],"links":[]}',
    ]);

    SapiensServer::actingAs($this->user)
        ->tool(ScaffoldAppTool::class, [
            'name' => 'Con Automatizacion',
            'description' => 'Mesa de ayuda con tickets.',
        ])
        ->assertOk()
        ->assertSee('record.created')
        ->assertSee('record.updated')
        ->assertSee('record.date_reached');

    // Suggested, never authored: a workflow needs a recipient or a deadline the
    // description does not contain, and a half-configured one is clutter.
    $app = App::where('user_id', $this->user->id)->where('slug', 'con_automatizacion')->firstOrFail();
    expect($app->versions()->first()->manifest['workflows'] ?? [])->toBe([]);
});

it('bills in the money of the language the brief was written in', function () {
    // A Portuguese school came out charging MX$ 241,00. Currency was a flat
    // default on the reasoning that it is a separate concern from language —
    // it is, but the same detection already ran on the description, and a
    // neutral default that is wrong on every amount is not neutral.
    //
    // The app factory makes an organization, whose membership observer assigns
    // a role that has to exist.
    $this->seed(RolesAndPermissionsSeeder::class);
    $service = app(AppManifestService::class);

    // Real briefs, not phrases: the detector reads a description, and a line
    // too short to have a language is exactly what falls back.
    $cases = [
        [
            'Escola de idiomas',
            'Escola de idiomas com turmas presenciais e online. ALUNOS: nome completo, e-mail, telefone e nível atual. TURMAS: cada turma tem um professor, com dia e horário, data de início e situação. A secretaria precisa ver quais mensalidades estão atrasadas.',
            'BRL', 'America/Sao_Paulo',
        ],
        [
            'Cabinet',
            'Cabinet d\'avocats spécialisé en droit des affaires. DOSSIERS : chaque dossier appartient à un client, avec la date d\'ouverture et les honoraires convenus. Le cabinet doit voir quels dossiers sont ouverts et quelles factures sont en retard.',
            'EUR', 'Europe/Paris',
        ],
        [
            'Field service',
            'Field service operation for a company that installs commercial HVAC equipment. WORK ORDERS: each one is for a piece of equipment, with an opened date and a promised date. Dispatch needs to see which work orders are past their promised date.',
            'USD', 'America/New_York',
        ],
        [
            'Arrendamientos',
            'Administración de arrendamiento de inmuebles. CONTRATOS: cada contrato es de un inmueble y de un inquilino, con fecha de inicio y renta mensual pactada. La operadora necesita ver la cobranza del mes.',
            'MXN', 'America/Mexico_City',
        ],
    ];

    foreach ($cases as [$name, $description, $currency, $timezone]) {
        $app = App::factory()->create([
            'user_id' => $this->user->id,
            'name' => $name,
            'description' => $description,
        ]);

        $settings = $service->initialManifest($app)['settings'];

        expect($settings['default_currency'])->toBe($currency, $name)
            ->and($settings['default_timezone'])->toBe($timezone, $name);
    }
});
