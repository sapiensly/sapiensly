<?php

use App\Enums\MembershipRole;
use App\Models\App;
use App\Models\AppFile;
use App\Models\Record;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\RecordExtractionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/**
 * Read a document, fill a form.
 *
 * The interesting behaviour is everything AROUND the model call, because the
 * model call is the part nobody can assert on: what matters is that an
 * extraction writes nothing, cannot reach another tenant's file, is refused to
 * somebody who could not save the result anyway, and comes back empty rather
 * than wrong when it fails.
 *
 * Filling a form somebody then checks is help. Writing a record they never saw
 * is a liability with a good demo.
 */
function extractApp(User $owner, array $overrides = []): App
{
    $app = App::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $owner->organization_id,
        'slug' => 'ext_'.Str::lower(Str::random(6)),
        'name' => 'Facturas',
    ]);

    $manifest = array_replace_recursive([
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => $app->slug,
        'name' => 'Facturas',
        'version' => 1,
        'settings' => ['default_locale' => 'es-MX'],
        'objects' => [[
            'id' => 'obj_facturas001',
            'name' => 'Facturas',
            'slug' => 'facturas',
            'fields' => [
                ['id' => 'fld_folio00001', 'name' => 'Folio', 'slug' => 'folio', 'type' => 'string'],
                ['id' => 'fld_total00001', 'name' => 'Total', 'slug' => 'total', 'type' => 'number'],
                [
                    'id' => 'fld_estado0001', 'name' => 'Estado', 'slug' => 'estado',
                    'type' => 'single_select',
                    'options' => [
                        ['id' => 'opt_pagada00001', 'value' => 'pagada', 'label' => 'Pagada'],
                        ['id' => 'opt_pendiente01', 'value' => 'pendiente', 'label' => 'Pendiente'],
                    ],
                ],
                // Derived fields are not things a photograph answers.
                [
                    'id' => 'fld_etiqueta001', 'name' => 'Etiqueta', 'slug' => 'etiqueta',
                    'type' => 'formula', 'expression' => 'upper({{folio}})',
                    'return_type' => 'string', 'readonly' => true,
                ],
            ],
        ]],
        'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
    ], $overrides);

    app(AppManifestService::class)->createVersion($app, $manifest, $owner);

    return $app->fresh();
}

function appFileFor(App $app, User $owner): AppFile
{
    return AppFile::create([
        'id' => 'fil_'.strtolower((string) Str::ulid()),
        'app_id' => $app->id,
        'organization_id' => $app->organization_id,
        'disk' => 'local',
        'storage_path' => 'facturas/x.png',
        'original_name' => 'factura.png',
        'mime' => 'image/png',
        'size_bytes' => 1024,
        'uploaded_by_user_id' => $owner->id,
    ]);
}

it('writes nothing, whatever the model says', function () {
    // The whole shape of the feature. An extraction is a suggestion; the record
    // is created by the form's own submit, through the ordinary write path.
    $owner = User::factory()->create();
    $app = extractApp($owner);
    $file = appFileFor($app, $owner);

    $this->mock(RecordExtractionService::class)
        ->shouldReceive('extract')
        ->andReturn(['values' => ['folio' => 'A-1', 'total' => 1250], 'error' => null]);

    $this->actingAs($owner)
        ->postJson("/r/{$app->slug}/objects/facturas/extract", ['file_id' => $file->id])
        ->assertOk()
        ->assertJson(['values' => ['folio' => 'A-1', 'total' => 1250]]);

    expect(Record::where('app_id', $app->id)->count())->toBe(0);
});

it('will not read a file belonging to another app', function () {
    // A file id is guessable enough that reading somebody else's invoice
    // through a model would otherwise be one request away.
    $owner = User::factory()->create();
    $app = extractApp($owner);
    $other = extractApp($owner);
    $strangersFile = appFileFor($other, $owner);

    $this->actingAs($owner)
        ->postJson("/r/{$app->slug}/objects/facturas/extract", ['file_id' => $strangersFile->id])
        ->assertNotFound();
});

it('is refused to a role that could not save the result anyway', function () {
    // Offering it to somebody without create would be a dead end with a model
    // bill attached.
    $this->seed(RolesAndPermissionsSeeder::class);

    $org = mcpOrg();
    $owner = mcpMember($org);
    $app = extractApp($owner, ['permissions' => [
        'roles' => [['id' => 'rol_lector0001', 'slug' => 'lector', 'name' => 'Lector', 'is_default' => true]],
        'object_policies' => [[
            'object_id' => 'obj_facturas001',
            'role_id' => 'rol_lector0001',
            'actions' => ['read'],
        ]],
    ]]);
    $app->update(['visibility' => 'organization']);

    $file = appFileFor($app, $owner);

    $this->actingAs(mcpMember($org, MembershipRole::Member))
        ->postJson("/r/{$app->slug}/objects/facturas/extract", ['file_id' => $file->id])
        ->assertForbidden();
});

it('is closed to somebody who cannot see the app', function () {
    $owner = User::factory()->create();
    $app = extractApp($owner);
    $file = appFileFor($app, $owner);

    $this->actingAs(User::factory()->create())
        ->postJson("/r/{$app->slug}/objects/facturas/extract", ['file_id' => $file->id])
        ->assertNotFound();
});

it('says so plainly when no vision model is configured', function () {
    // A platform setting, named as one: somebody hunting for it inside their
    // own app would never find it.
    $owner = User::factory()->create();
    $app = extractApp($owner);
    $file = appFileFor($app, $owner);

    $response = $this->actingAs($owner)
        ->postJson("/r/{$app->slug}/objects/facturas/extract", ['file_id' => $file->id])
        ->assertOk();

    expect($response->json('values'))->toBe([])
        ->and($response->json('error'))->not->toBeNull();
});

it('keeps only fields the object really has', function () {
    // A model asked for four fields sometimes answers with five, or spells one
    // its own way. An unknown key reaching the form is a value nobody can see
    // and nobody can correct.
    $service = app(RecordExtractionService::class);
    $method = new ReflectionMethod($service, 'mapToFields');

    $fields = [
        'folio' => ['slug' => 'folio', 'type' => 'string'],
        'estado' => [
            'slug' => 'estado', 'type' => 'single_select',
            'options' => [['value' => 'pagada'], ['value' => 'pendiente']],
        ],
    ];

    $mapped = $method->invoke($service, [
        'folio' => 'A-1',
        'estado' => 'pagada',
        'inventado' => 'algo',
        'vacio' => '',
    ], $fields);

    expect($mapped)->toBe(['folio' => 'A-1', 'estado' => 'pagada']);
});

it('drops a select value the field would never accept', function () {
    // The model is told the exact options. This is what happens when it
    // answers "Paid" anyway — the form would silently discard it, and a
    // silently discarded value is one somebody believes was filled.
    $service = app(RecordExtractionService::class);
    $method = new ReflectionMethod($service, 'mapToFields');

    $mapped = $method->invoke($service, ['estado' => 'Paid'], [
        'estado' => [
            'slug' => 'estado', 'type' => 'single_select',
            'options' => [['value' => 'pagada'], ['value' => 'pendiente']],
        ],
    ]);

    expect($mapped)->toBe([]);
});

it('does not ask a photograph for things a photograph cannot answer', function () {
    // Rollups are worked out from other records; a relation needs a record id.
    // Asking for either invites an answer that cannot be used.
    $owner = User::factory()->create();
    $app = extractApp($owner);
    $manifest = app(AppManifestService::class)->getActiveManifest($app);

    $service = app(RecordExtractionService::class);
    $method = new ReflectionMethod($service, 'describeFields');
    $described = $method->invoke($service, $manifest['objects'][0]);

    expect(array_keys($described))->toBe(['folio', 'total', 'estado']);
});

it('finds the JSON a model buried in prose', function () {
    // They fence it, apologise before it and explain after it, whatever the
    // prompt says. The braces are the only reliable landmark.
    $service = app(RecordExtractionService::class);
    $method = new ReflectionMethod($service, 'decode');

    expect($method->invoke($service, "Sure! Here you go:\n```json\n{\"folio\": \"A-1\"}\n```\nHope that helps."))
        ->toBe(['folio' => 'A-1'])
        ->and($method->invoke($service, 'no json at all'))->toBe([]);
});
