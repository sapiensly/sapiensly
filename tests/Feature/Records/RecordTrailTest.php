<?php

use App\Enums\Visibility;
use App\Models\App;
use App\Models\RecordEvent;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\RecordTrail;
use App\Services\Records\RecordWriteService;
use Illuminate\Support\Str;

/**
 * What has happened to a record.
 *
 * The history and the comments are one trail because they are read as one:
 * "why is this still waiting?" is answered half by the machine ("Estado:
 * Recibida → Esperando refacción, Ana") and half by a person ("llamé al
 * cliente, no contesta").
 */
function trailApp(User $owner, array $policies = []): array
{
    $app = App::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $owner->organization_id,
        'slug' => 'trail_'.strtolower(Str::random(6)),
        'name' => 'Órdenes',
        // Turned on deliberately, because the default is off: keeping a record
        // of who did what is a business's decision. Set on the APP rather than
        // the organisation so the fixture does not need one.
        'activity_retention_months' => 12,
        // Shared with the organisation: a private app is invisible to anyone
        // but its owner, and the role tests need a second reader.
        'visibility' => Visibility::Organization,
    ]);

    $manifest = [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => $app->slug,
        'name' => 'Órdenes',
        'version' => 1,
        'settings' => ['default_locale' => 'es-MX'],
        'objects' => [[
            'id' => 'obj_ordenes001',
            'name' => 'Órdenes',
            'slug' => 'ordenes',
            'fields' => [
                ['id' => 'fld_folio00001', 'name' => 'Folio', 'slug' => 'folio', 'type' => 'string'],
                ['id' => 'fld_estado0001', 'name' => 'Estado', 'slug' => 'estado', 'type' => 'single_select', 'options' => [
                    ['id' => 'opt_recibida01', 'value' => 'recibida', 'label' => 'Recibida'],
                    ['id' => 'opt_entregada1', 'value' => 'entregada', 'label' => 'Entregada'],
                ]],
                ['id' => 'fld_total00001', 'name' => 'Total', 'slug' => 'total', 'type' => 'currency'],
            ],
        ]],
        'pages' => [],
        'permissions' => array_merge([
            'roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]],
        ], $policies),
    ];

    app(AppManifestService::class)->createVersion($app, $manifest, $owner);

    return [$app->fresh(), $manifest];
}

it('records what a change actually changed, by name', function () {
    $owner = User::factory()->create(['name' => 'Ana']);
    [$app, $manifest] = trailApp($owner);
    $writes = app(RecordWriteService::class);

    $record = $writes->create($app, $manifest, 'obj_ordenes001', [
        'folio' => 'A-1', 'estado' => 'recibida',
    ], $owner);

    $writes->update($app, $manifest, $record, ['estado' => 'entregada'], $owner);

    $events = RecordEvent::where('record_id', $record->id)->orderBy('created_at')->get();

    expect($events->pluck('kind')->all())->toBe(['created', 'updated'])
        ->and($events[1]->actor_name)->toBe('Ana');

    // The field's LABEL as it read at the time, so the trail survives a rename
    // or a removal; the values raw, so it stays a truthful record of what was
    // written. Turning "entregada" into "Entregada" is the reader's job.
    expect($events[1]->changes)->toHaveCount(1)
        ->and($events[1]->changes[0])->toMatchArray([
            'field' => 'estado',
            'label' => 'Estado',
            'from' => 'recibida',
            'to' => 'entregada',
        ]);
});

it('says nothing when a save changed nothing', function () {
    // A trail full of events nobody caused is a trail nobody scrolls.
    $owner = User::factory()->create();
    [$app, $manifest] = trailApp($owner);
    $writes = app(RecordWriteService::class);

    $record = $writes->create($app, $manifest, 'obj_ordenes001', ['folio' => 'A-1'], $owner);
    $writes->update($app, $manifest, $record, ['folio' => 'A-1'], $owner);

    expect(RecordEvent::where('record_id', $record->id)->where('kind', 'updated')->count())->toBe(0);
});

it('keeps the deletion, which is the one event a deleted record still needs', function () {
    $owner = User::factory()->create();
    [$app, $manifest] = trailApp($owner);
    $writes = app(RecordWriteService::class);

    $record = $writes->create($app, $manifest, 'obj_ordenes001', ['folio' => 'A-1'], $owner);
    $id = $record->id;
    $writes->delete($record, $app, $manifest, $owner);

    expect(RecordEvent::where('record_id', $id)->where('kind', 'deleted')->count())->toBe(1);
});

it('answers null rather than throwing when there is nothing to write', function () {
    // The write path leans on this: every trail entry is best-effort, because a
    // create that succeeded and then threw because its history could not be
    // saved would be a far worse bug than a missing line in a list. Forcing a
    // real database failure needs scaffolding more fragile than the guarantee
    // it would test, so what is pinned here is the edge that IS reachable.
    $owner = User::factory()->create();
    [$app, $manifest] = trailApp($owner);

    $record = app(RecordWriteService::class)
        ->create($app, $manifest, 'obj_ordenes001', ['folio' => 'A-1'], $owner);

    $trail = app(RecordTrail::class);

    expect($trail->comment($app, $record, '   ', $owner))->toBeNull()
        ->and(RecordEvent::where('record_id', $record->id)->where('kind', 'comment')->count())->toBe(0)
        ->and($record->exists)->toBeTrue();
});

it('serves the trail to somebody who may read the record', function () {
    $owner = User::factory()->create(['name' => 'Ana']);
    [$app, $manifest] = trailApp($owner);
    $writes = app(RecordWriteService::class);

    $record = $writes->create($app, $manifest, 'obj_ordenes001', ['folio' => 'A-1'], $owner);

    $response = $this->actingAs($owner)
        ->getJson("/r/{$app->slug}/records/{$record->id}/trail")
        ->assertOk();

    expect($response->json('events'))->toHaveCount(1)
        ->and($response->json('events.0.kind'))->toBe('created')
        ->and($response->json('events.0.actor'))->toBe('Ana');
});

it('accepts a comment and puts it in the same trail', function () {
    $owner = User::factory()->create(['name' => 'Ana']);
    [$app, $manifest] = trailApp($owner);
    $record = app(RecordWriteService::class)->create($app, $manifest, 'obj_ordenes001', ['folio' => 'A-1'], $owner);

    $this->actingAs($owner)
        ->postJson("/r/{$app->slug}/records/{$record->id}/trail", [
            'body' => 'Llamé al cliente, no contesta.',
        ])
        ->assertCreated()
        ->assertJsonPath('event.kind', 'comment')
        ->assertJsonPath('event.body', 'Llamé al cliente, no contesta.');

    $response = $this->actingAs($owner)->getJson("/r/{$app->slug}/records/{$record->id}/trail");

    // Newest first, machine and human interleaved — they are read as one list.
    expect($response->json('events'))->toHaveCount(2)
        ->and($response->json('events.0.kind'))->toBe('comment');
});

it('lets a read-only role read the trail but not add to it', function () {
    // A comment is a change to the record's story, and somebody with a
    // read-only grant is a reader.
    //
    // Exercised through `as_role`, the runtime's own role preview, rather than
    // by seating a second person: whether one account can SEE another's app is
    // a different machine with its own tests, and it would be all this one
    // ended up proving.
    $owner = User::factory()->create();
    [$app, $manifest] = trailApp($owner, ['object_policies' => [
        ['role_id' => 'rol_admin00001', 'object_id' => 'obj_ordenes001', 'actions' => ['create', 'read', 'update', 'delete']],
        ['role_id' => 'rol_viewer0001', 'object_id' => 'obj_ordenes001', 'actions' => ['read']],
    ], 'roles' => [
        ['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true],
        ['id' => 'rol_viewer0001', 'slug' => 'viewer', 'name' => 'Viewer', 'is_default' => false],
    ]]);

    $record = app(RecordWriteService::class)->create($app, $manifest, 'obj_ordenes001', ['folio' => 'A-1'], $owner);

    $this->actingAs($owner)
        ->getJson("/r/{$app->slug}/records/{$record->id}/trail?as_role=viewer")
        ->assertOk();

    $this->actingAs($owner)
        ->postJson("/r/{$app->slug}/records/{$record->id}/trail?as_role=viewer", ['body' => 'nope'])
        ->assertNotFound();
});

it('is not a way to learn that a record you cannot see exists', function () {
    $owner = User::factory()->create();
    [$app, $manifest] = trailApp($owner);
    $record = app(RecordWriteService::class)->create($app, $manifest, 'obj_ordenes001', ['folio' => 'A-1'], $owner);

    $this->actingAs(User::factory()->create())
        ->getJson("/r/{$app->slug}/records/{$record->id}/trail")
        ->assertNotFound();
});
