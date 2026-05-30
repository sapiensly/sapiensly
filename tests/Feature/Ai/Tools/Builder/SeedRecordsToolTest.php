<?php

use App\Ai\Tools\Builder\ProposeChangeTool;
use App\Ai\Tools\Builder\SeedRecordsTool;
use App\Models\App;
use App\Models\Record;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\ManifestValidator;
use App\Services\Records\RecordWriteService;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request as ToolRequest;

function srid(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

function seedManifest(string $appId, string $objId, string $titleFld, string $doneFld, string $prioFld): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => 'mini',
        'name' => 'Mini',
        'version' => 1,
        'objects' => [[
            'id' => $objId,
            'slug' => 'tareas',
            'name' => 'Tarea',
            'fields' => [
                ['id' => $titleFld, 'slug' => 'titulo', 'name' => 'Título', 'type' => 'string', 'required' => true],
                ['id' => $doneFld, 'slug' => 'completada', 'name' => 'Completada', 'type' => 'boolean', 'default' => false],
                [
                    'id' => $prioFld, 'slug' => 'prioridad', 'name' => 'Prioridad', 'type' => 'single_select',
                    'options' => [
                        ['id' => srid('opt'), 'value' => 'baja', 'label' => 'Baja', 'color' => '#10B981'],
                        ['id' => srid('opt'), 'value' => 'media', 'label' => 'Media', 'color' => '#F59E0B'],
                        ['id' => srid('opt'), 'value' => 'alta', 'label' => 'Alta', 'color' => '#EF4444'],
                    ],
                ],
            ],
        ]],
        'pages' => [],
        'permissions' => [
            'roles' => [['id' => srid('rol'), 'slug' => 'admin', 'name' => 'Admin']],
        ],
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->testApp = App::factory()->create(['user_id' => $this->user->id]);
    $this->objId = srid('obj');
    $this->titleFld = srid('fld');
    $this->doneFld = srid('fld');
    $this->prioFld = srid('fld');

    $manifest = seedManifest($this->testApp->id, $this->objId, $this->titleFld, $this->doneFld, $this->prioFld);
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->user);

    // Pass the FRESH App into the tool — createVersion updates
    // current_version_id on the DB row, and the in-memory instance is
    // stale. Without ->fresh() the tool's getActiveManifest() returns
    // null and every call fails with "No active manifest found".
    $this->tool = new SeedRecordsTool(
        $this->testApp->fresh(),
        app(AppManifestService::class),
        app(RecordWriteService::class),
        $this->user,
    );
});

function callSeedTool(SeedRecordsTool $tool, array $args): array
{
    $request = new ToolRequest($args);
    $raw = $tool->handle($request);

    return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
}

it('creates records when called with the object slug', function () {
    $result = callSeedTool($this->tool, [
        'object_id_or_slug' => 'tareas',
        'records' => [
            ['titulo' => 'Llamar al proveedor', 'prioridad' => 'alta', 'completada' => false],
            ['titulo' => 'Revisar inventario', 'prioridad' => 'media', 'completada' => false],
            ['titulo' => 'Mandar reporte mensual', 'prioridad' => 'alta', 'completada' => true],
        ],
    ]);

    expect($result['created'])->toBe(3)
        ->and($result['requested'])->toBe(3)
        ->and($result['errors'])->toBe([])
        ->and($result['object_slug'])->toBe('tareas')
        ->and(count($result['created_ids']))->toBe(3);

    $rows = Record::query()
        ->where('app_id', $this->testApp->id)
        ->where('object_definition_id', $this->objId)
        ->get();

    expect($rows->count())->toBe(3)
        ->and($rows->pluck('data.titulo')->all())->toContain('Llamar al proveedor', 'Revisar inventario', 'Mandar reporte mensual')
        ->and($rows->pluck('data.prioridad')->all())->toContain('alta', 'media');
});

it('also accepts the canonical object id', function () {
    $result = callSeedTool($this->tool, [
        'object_id_or_slug' => $this->objId,
        'records' => [
            ['titulo' => 'Una tarea', 'prioridad' => 'baja'],
        ],
    ]);

    expect($result['created'])->toBe(1);
});

it('keeps going past validation errors and reports them per-row', function () {
    $result = callSeedTool($this->tool, [
        'object_id_or_slug' => 'tareas',
        'records' => [
            ['titulo' => 'OK record', 'prioridad' => 'alta'],
            // Missing required `titulo` — should fail this one but not the others.
            ['prioridad' => 'media'],
            ['titulo' => 'Another OK', 'prioridad' => 'baja'],
        ],
    ]);

    expect($result['created'])->toBe(2)
        ->and($result['requested'])->toBe(3)
        ->and(count($result['errors']))->toBe(1)
        ->and($result['errors'][0]['index'])->toBe(1);
});

it('refuses an unknown object and lists the available slugs', function () {
    $result = callSeedTool($this->tool, [
        'object_id_or_slug' => 'no_existe',
        'records' => [['titulo' => 'x']],
    ]);

    expect($result['error'])->toContain("No object matched 'no_existe'")
        ->and($result['available_object_slugs'])->toContain('tareas');
});

it('caps a single call at 100 records', function () {
    $records = [];
    for ($i = 0; $i < 101; $i++) {
        $records[] = ['titulo' => "Tarea {$i}", 'prioridad' => 'baja'];
    }

    $result = callSeedTool($this->tool, [
        'object_id_or_slug' => 'tareas',
        'records' => $records,
    ]);

    expect($result['error'])->toContain('Max is 100');

    // Nothing should have been written.
    expect(Record::query()
        ->where('app_id', $this->testApp->id)
        ->where('object_definition_id', $this->objId)
        ->count())->toBe(0);
});

it('finds an object that was added to the running draft in the same turn', function () {
    // This reproduces the bug: the IA creates a new object via
    // propose_change and then immediately tries to seed records into it
    // within the same turn. Before the fix, seed_records would read the
    // active manifest, NOT see the new object, and fail with "No object
    // matched". With the proposeTool wired in, it consults the running
    // draft and finds the object that's only one breath away from being
    // persisted.
    $proposeTool = new ProposeChangeTool(
        $this->testApp->fresh(),
        app(AppManifestService::class),
        app(ManifestValidator::class),
    );

    $newObjId = srid('obj');
    $newTitleFld = srid('fld');
    $proposalOk = $proposeTool->recordProposal(
        [['op' => 'add', 'path' => '/objects/-', 'value' => [
            'id' => $newObjId,
            'slug' => 'peliculas',
            'name' => 'Película',
            'fields' => [
                ['id' => $newTitleFld, 'slug' => 'titulo', 'name' => 'Título', 'type' => 'string', 'required' => true],
            ],
        ]]],
        'add peliculas object',
    );
    expect($proposalOk['ok'])->toBeTrue();

    // Build a new SeedRecordsTool with the propose tool wired in (mirrors
    // BuilderAiService).
    $seed = new SeedRecordsTool(
        $this->testApp->fresh(),
        app(AppManifestService::class),
        app(RecordWriteService::class),
        $this->user,
        $proposeTool,
    );

    $result = callSeedTool($seed, [
        'object_id_or_slug' => 'peliculas',
        'records' => [
            ['titulo' => 'Pulp Fiction'],
            ['titulo' => 'El Padrino'],
        ],
    ]);

    expect($result['created'])->toBe(2)
        ->and($result['errors'])->toBe([])
        ->and($result['object_id'])->toBe($newObjId);

    // Records were persisted pointing at the draft-only object id. They'll
    // become "owned" by the new AppVersion once the turn auto-applies.
    $persisted = Record::query()
        ->where('app_id', $this->testApp->id)
        ->where('object_definition_id', $newObjId)
        ->get();
    expect($persisted->count())->toBe(2)
        ->and($persisted->pluck('data.titulo')->all())->toContain('Pulp Fiction', 'El Padrino');
});

it('requires object_id_or_slug and a non-empty records array', function () {
    $missingObject = callSeedTool($this->tool, ['records' => [['titulo' => 'x']]]);
    expect($missingObject['error'])->toContain('object_id_or_slug is required');

    $emptyRecords = callSeedTool($this->tool, [
        'object_id_or_slug' => 'tareas',
        'records' => [],
    ]);
    expect($emptyRecords['error'])->toContain('non-empty array');
});
