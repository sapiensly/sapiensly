<?php

use App\Models\App;
use App\Models\AppVersion;
use App\Models\User;
use App\Models\WorkflowRun;
use App\Services\Manifest\AppManifestService;
use Illuminate\Support\Str;

/**
 * Identifier helpers — match the schema's prefixed-ULID pattern.
 */
function wfid(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

/**
 * Minimal manifest with one object and one manual workflow already set up.
 * Tests can `replace` or `add` workflows against this baseline.
 */
function wfManifest(string $appId, array $workflows = []): array
{
    $objId = wfid('obj');
    $fldId = wfid('fld');

    return [
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => 'wftest_'.strtolower(Str::random(6)),
        'name' => 'Workflow Test App',
        'version' => 1,
        'objects' => [[
            'id' => $objId,
            'slug' => 'tareas',
            'name' => 'Tarea',
            'fields' => [
                ['id' => $fldId, 'slug' => 'titulo', 'name' => 'Título', 'type' => 'string', 'required' => true],
            ],
        ]],
        'pages' => [],
        'workflows' => $workflows,
        'permissions' => [
            'roles' => [['id' => wfid('rol'), 'slug' => 'admin', 'name' => 'Admin']],
        ],
    ];
}

function simpleManualWorkflow(string $id, string $name = 'Saludar'): array
{
    return [
        'id' => $id,
        'slug' => 'saludar_'.strtolower(Str::random(4)),
        'name' => $name,
        'trigger' => ['type' => 'manual', 'label' => 'Probar'],
        'steps' => [[
            'id' => wfid('stp'),
            'type' => 'log',
            'message' => 'Hola mundo',
        ]],
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->testApp = App::factory()->create(['user_id' => $this->user->id, 'visibility' => 'private']);
});

it('PUT /workflows/{wfId} replaces the matching workflow and creates a new AppVersion', function () {
    $wfId = wfid('wkf');
    app(AppManifestService::class)->createVersion(
        $this->testApp,
        wfManifest($this->testApp->id, [simpleManualWorkflow($wfId, 'Antes')]),
        $this->user,
    );
    $startVersions = AppVersion::query()->where('app_id', $this->testApp->id)->count();

    $newWorkflow = simpleManualWorkflow($wfId, 'Después');

    $this->actingAs($this->user)
        ->putJson("/apps/{$this->testApp->id}/builder/workflows/{$wfId}", [
            'workflow' => $newWorkflow,
        ])
        ->assertOk()
        ->assertJsonPath('manifest.workflows.0.name', 'Después');

    expect(AppVersion::query()->where('app_id', $this->testApp->id)->count())->toBe($startVersions + 1);
});

it('PUT /workflows/{wfId} creates the workflow when not present', function () {
    // Manifest has no workflows key — endpoint must create the array first.
    $manifest = wfManifest($this->testApp->id, []);
    unset($manifest['workflows']);
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->user);

    $wfId = wfid('wkf');
    $this->actingAs($this->user)
        ->putJson("/apps/{$this->testApp->id}/builder/workflows/{$wfId}", [
            'workflow' => simpleManualWorkflow($wfId, 'Recién hecho'),
        ])
        ->assertOk()
        ->assertJsonPath('manifest.workflows.0.id', $wfId);
});

it('PUT /workflows/{wfId} rejects an invalid workflow with 422', function () {
    $wfId = wfid('wkf');
    app(AppManifestService::class)->createVersion(
        $this->testApp,
        wfManifest($this->testApp->id, [simpleManualWorkflow($wfId)]),
        $this->user,
    );

    // Step with unknown type — schema validator catches it.
    $bogus = simpleManualWorkflow($wfId);
    $bogus['steps'] = [['id' => wfid('stp'), 'type' => 'totally_made_up']];

    $this->actingAs($this->user)
        ->putJson("/apps/{$this->testApp->id}/builder/workflows/{$wfId}", [
            'workflow' => $bogus,
        ])
        ->assertStatus(422)
        ->assertJsonPath('error', 'invalid_manifest');
});

it('PUT /workflows/{wfId} refuses when the body id differs from the URL param', function () {
    $wfId = wfid('wkf');
    app(AppManifestService::class)->createVersion(
        $this->testApp,
        wfManifest($this->testApp->id, [simpleManualWorkflow($wfId)]),
        $this->user,
    );

    $other = simpleManualWorkflow(wfid('wkf'));

    $this->actingAs($this->user)
        ->putJson("/apps/{$this->testApp->id}/builder/workflows/{$wfId}", [
            'workflow' => $other,
        ])
        ->assertStatus(422);
});

it('POST /workflows/{wfId}/run executes a manual workflow and returns the run with its step', function () {
    $wfId = wfid('wkf');
    app(AppManifestService::class)->createVersion(
        $this->testApp,
        wfManifest($this->testApp->id, [simpleManualWorkflow($wfId)]),
        $this->user,
    );

    $payload = $this->actingAs($this->user)
        ->postJson("/apps/{$this->testApp->id}/builder/workflows/{$wfId}/run")
        ->assertOk()
        ->json();

    expect($payload['run']['status'])->toBe('completed')
        ->and($payload['run']['workflow_id'])->toBe($wfId)
        ->and($payload['run']['trigger_type'])->toBe('manual')
        ->and(count($payload['run']['steps']))->toBe(1)
        ->and($payload['run']['steps'][0]['step_type'])->toBe('log')
        ->and($payload['run']['steps'][0]['status'])->toBe('completed');

    // Persisted too — the editor's polling could re-fetch the same run later.
    expect(WorkflowRun::query()->where('app_id', $this->testApp->id)->count())->toBe(1);
});

it('POST /workflows/{wfId}/run refuses non-manual triggers', function () {
    $wfId = wfid('wkf');
    $objId = wfid('obj');
    $recordTriggerWorkflow = [
        'id' => $wfId,
        'slug' => 'on_create',
        'name' => 'On create',
        'trigger' => ['type' => 'record.created', 'object_id' => $objId],
        'steps' => [['id' => wfid('stp'), 'type' => 'log', 'message' => 'created']],
    ];
    // Fix the object_id in the manifest to match what the trigger references.
    $manifest = wfManifest($this->testApp->id, [$recordTriggerWorkflow]);
    $manifest['objects'][0]['id'] = $objId;
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->user);

    $this->actingAs($this->user)
        ->postJson("/apps/{$this->testApp->id}/builder/workflows/{$wfId}/run")
        ->assertStatus(422);
});

it('POST /workflows/{wfId}/run 404s when the workflow id is unknown', function () {
    app(AppManifestService::class)->createVersion(
        $this->testApp,
        wfManifest($this->testApp->id, []),
        $this->user,
    );

    $this->actingAs($this->user)
        ->postJson("/apps/{$this->testApp->id}/builder/workflows/wkf_does_not_exist/run")
        ->assertNotFound();
});

it('blocks cross-app workflow access', function () {
    $wfId = wfid('wkf');
    app(AppManifestService::class)->createVersion(
        $this->testApp,
        wfManifest($this->testApp->id, [simpleManualWorkflow($wfId)]),
        $this->user,
    );

    $stranger = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($stranger)
        ->putJson("/apps/{$this->testApp->id}/builder/workflows/{$wfId}", [
            'workflow' => simpleManualWorkflow($wfId),
        ])
        ->assertForbidden();

    $this->actingAs($stranger)
        ->postJson("/apps/{$this->testApp->id}/builder/workflows/{$wfId}/run")
        ->assertForbidden();
});
