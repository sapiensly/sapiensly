<?php

use App\Models\App;
use App\Models\Integration;
use App\Models\Record;
use App\Models\User;
use App\Models\WorkflowRun;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\InvalidManifestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function ac_id(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

function ac_manifest(string $appId, string $slug): array
{
    $objId = ac_id('obj');
    $nombre = ac_id('fld');
    $monto = ac_id('fld');

    return [
        '_object_id' => $objId,
        '_nombre_id' => $nombre,
        '_monto_id' => $monto,
        'manifest' => [
            'schema_version' => '1.0.0',
            'id' => $appId,
            'slug' => $slug,
            'name' => 'Action Test',
            'version' => 1,
            'objects' => [[
                'id' => $objId,
                'slug' => 'clientes',
                'name' => 'Cliente',
                'fields' => [
                    ['id' => $nombre, 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string', 'required' => true, 'max_length' => 100],
                    ['id' => $monto, 'slug' => 'monto', 'name' => 'Monto', 'type' => 'currency', 'currency_code' => 'MXN'],
                ],
            ]],
            'pages' => [],
            'permissions' => ['roles' => [['id' => ac_id('rol'), 'slug' => 'admin', 'name' => 'Admin']]],
        ],
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->testApp = App::factory()->create(['user_id' => $this->user->id, 'slug' => 'actions_app', 'visibility' => 'private']);
    $bundle = ac_manifest($this->testApp->id, 'actions_app');
    $this->objectId = $bundle['_object_id'];
    $this->nombreId = $bundle['_nombre_id'];
    app(AppManifestService::class)->createVersion($this->testApp, $bundle['manifest'], $this->user);
});

it('redirects guests away from the actions endpoint', function () {
    $this->post('/r/actions_app/actions', ['actions' => []])->assertRedirect('/login');
});

it('returns 404 when the app slug does not exist', function () {
    $this->actingAs($this->user)
        ->postJson('/r/ghost/actions', ['actions' => []])
        ->assertNotFound();
});

it('executes a create_record action and stores the record', function () {
    $response = $this->actingAs($this->user)->postJson('/r/actions_app/actions', [
        'actions' => [[
            'type' => 'create_record',
            'object_id' => $this->objectId,
            'values' => ['nombre' => '{{form.nombre}}', 'monto' => '{{form.monto}}'],
        ]],
        'form' => ['nombre' => 'Ana', 'monto' => 1500],
    ]);

    $response->assertOk()->assertJsonPath('ok', true);

    expect(Record::query()->where('app_id', $this->testApp->id)->count())->toBe(1);
    $record = Record::query()->where('app_id', $this->testApp->id)->first();
    expect($record->data['nombre'])->toBe('Ana')
        ->and((float) $record->data['monto'])->toBe(1500.0);
});

it('returns 422 with field-level errors when validation fails', function () {
    $response = $this->actingAs($this->user)->postJson('/r/actions_app/actions', [
        'actions' => [[
            'type' => 'create_record',
            'object_id' => $this->objectId,
            'values' => ['nombre' => '{{form.nombre}}'],
        ]],
        'form' => ['nombre' => ''], // empty + required
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('errors.0.type', 'validation')
        ->assertJsonStructure(['errors' => ['0' => ['fields' => ['nombre']]]]);

    expect(Record::query()->where('app_id', $this->testApp->id)->count())->toBe(0);
});

it('echoes client-side actions back to the caller', function () {
    $response = $this->actingAs($this->user)->postJson('/r/actions_app/actions', [
        'actions' => [
            ['type' => 'create_record', 'object_id' => $this->objectId, 'values' => ['nombre' => 'X']],
            ['type' => 'show_toast', 'message' => 'Saved', 'level' => 'success'],
            ['type' => 'close_modal'],
            ['type' => 'refresh'],
        ],
    ]);

    $response->assertOk();
    $body = $response->json();
    expect($body['client_actions'])->toHaveCount(3)
        ->and($body['client_actions'][0]['type'])->toBe('show_toast')
        ->and($body['client_actions'][1]['type'])->toBe('close_modal');
});

it('rejects an unknown action type', function () {
    $response = $this->actingAs($this->user)->postJson('/r/actions_app/actions', [
        'actions' => [['type' => 'launch_missile']],
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('errors.0.type', 'unknown_action');
});

it('updates a record by record_id_expression', function () {
    $record = Record::create([
        'app_id' => $this->testApp->id,
        'object_definition_id' => $this->objectId,
        'data' => ['nombre' => 'Old', 'monto' => 100],
    ]);

    $response = $this->actingAs($this->user)->postJson('/r/actions_app/actions', [
        'actions' => [[
            'type' => 'update_record',
            'object_id' => $this->objectId,
            'record_id_expression' => '{{params.id}}',
            'values' => ['nombre' => '{{form.nombre}}'],
        ]],
        'params' => ['id' => $record->id],
        'form' => ['nombre' => 'New'],
    ]);

    $response->assertOk();
    expect($record->refresh()->data['nombre'])->toBe('New');
});

it('deletes a record by record_id_expression', function () {
    $record = Record::create([
        'app_id' => $this->testApp->id,
        'object_definition_id' => $this->objectId,
        'data' => ['nombre' => 'Bye'],
    ]);

    $response = $this->actingAs($this->user)->postJson('/r/actions_app/actions', [
        'actions' => [[
            'type' => 'delete_record',
            'object_id' => $this->objectId,
            'record_id_expression' => '{{params.id}}',
        ]],
        'params' => ['id' => $record->id],
    ]);

    $response->assertOk();
    expect(Record::query()->find($record->id))->toBeNull();
});

it('runs a manual workflow via the run_workflow action', function () {
    $wfId = ac_id('wkf');
    $this->testApp->refresh();
    $manifest = app(AppManifestService::class)->getActiveManifest($this->testApp);
    // schema_version is present in the active manifest
    $manifest['workflows'] = [[
        'id' => $wfId, 'slug' => 'manual_audit', 'name' => 'Manual Audit',
        'trigger' => ['type' => 'manual'],
        'steps' => [[
            'id' => ac_id('stp'),
            'type' => 'record.create',
            'object_id' => $this->objectId,
            'values' => ['nombre' => '{{trigger.who}}'],
        ]],
    ]];
    try {
        app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->user);
    } catch (InvalidManifestException $e) {
        dd($e->result->errorsArray());
    }

    $response = $this->actingAs($this->user)->postJson('/r/actions_app/actions', [
        'actions' => [[
            'type' => 'run_workflow',
            'workflow_id' => $wfId,
            'input' => ['who' => 'Workflow Bot'],
        ]],
    ]);

    $response->assertOk()->assertJsonPath('ok', true);

    expect(Record::query()->where('app_id', $this->testApp->id)->where('object_definition_id', $this->objectId)->count())->toBe(1);
    expect(WorkflowRun::query()->where('app_id', $this->testApp->id)->count())->toBe(1);
});

it('surfaces a failed workflow run as a server error', function () {
    $wfId = ac_id('wkf');
    $this->testApp->refresh();
    $manifest = app(AppManifestService::class)->getActiveManifest($this->testApp);
    // The step creates a Cliente but omits the required `nombre`, so the
    // engine marks the run failed. The controller must report that failure
    // rather than returning ok:true.
    $manifest['workflows'] = [[
        'id' => $wfId, 'slug' => 'bad_audit', 'name' => 'Bad Audit',
        'trigger' => ['type' => 'manual'],
        'steps' => [[
            'id' => ac_id('stp'),
            'type' => 'record.create',
            'object_id' => $this->objectId,
            'values' => ['monto' => '100'],
        ]],
    ]];
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->user);

    $response = $this->actingAs($this->user)->postJson('/r/actions_app/actions', [
        'actions' => [[
            'type' => 'run_workflow',
            'workflow_id' => $wfId,
        ]],
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('errors.0.type', 'server_error');

    expect($response->json('errors.0.message'))->not->toBeEmpty();
    expect(WorkflowRun::query()->where('app_id', $this->testApp->id)->where('status', 'failed')->count())->toBe(1);
});

it('routes a create_record action for a connected object to the external system', function () {
    Http::fake(['api.example.com/*' => Http::response(['id' => 'd99'], 201)]);

    $integration = Integration::factory()->forUser($this->user)->create([
        'base_url' => 'https://api.example.com',
        'auth_type' => 'bearer',
        'auth_config' => ['token' => 'TKN'],
    ]);

    $objId = ac_id('obj');
    $nameId = ac_id('fld');
    $app = App::factory()->create(['user_id' => $this->user->id, 'slug' => 'connected_app', 'visibility' => 'private']);
    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => 'connected_app',
        'name' => 'Connected',
        'version' => 1,
        'objects' => [[
            'id' => $objId,
            'slug' => 'deals',
            'name' => 'Deal',
            'fields' => [['id' => $nameId, 'slug' => 'name', 'name' => 'Name', 'type' => 'string']],
            'source' => [
                'type' => 'connected',
                'integration_id' => $integration->id,
                'id_path' => 'id',
                'operations' => [
                    'list' => ['method' => 'GET', 'path' => '/deals'],
                    'create' => ['method' => 'POST', 'path' => '/deals'],
                ],
                'field_map' => [['field_id' => $nameId, 'external_path' => 'properties.dealname']],
            ],
        ]],
        'pages' => [],
        'permissions' => ['roles' => [['id' => ac_id('rol'), 'slug' => 'admin', 'name' => 'Admin']]],
    ], $this->user);

    $response = $this->actingAs($this->user)->postJson('/r/connected_app/actions', [
        'actions' => [[
            'type' => 'create_record',
            'object_id' => $objId,
            'values' => ['name' => '{{form.name}}'],
        ]],
        'form' => ['name' => 'Acme'],
    ]);

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('results.0.record_id', 'd99');

    // Passthrough: the write hit the external system, not the internal store.
    expect(Record::query()->where('app_id', $app->id)->count())->toBe(0);
    Http::assertSent(fn ($req) => $req->method() === 'POST'
        && str_contains($req->url(), 'api.example.com/deals')
        && $req->data()['properties']['dealname'] === 'Acme');
});

it('hides apps the user cannot see', function () {
    $other = User::factory()->create(['email_verified_at' => now()]);
    $this->actingAs($other)
        ->postJson('/r/actions_app/actions', ['actions' => []])
        ->assertNotFound();
});
