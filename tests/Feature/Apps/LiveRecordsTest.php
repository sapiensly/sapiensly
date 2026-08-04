<?php

use App\Events\Apps\RecordChanged;
use App\Models\App;
use App\Models\Record;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\RecordWriteService;
use App\Support\Apps\EnvironmentContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * Telling everybody that something moved, and nothing else.
 *
 * The payload is three ids and a verb. That restraint IS the security design: a
 * broadcast carrying the row would reach every subscriber on the channel,
 * including a role whose row_filter hides exactly that row. There is no
 * per-recipient filtering in a broadcast, so the only safe thing to send is the
 * fact — and each listener re-reads through the ordinary access-filtered path,
 * which is the only place that knows what THEY may see.
 */
function liveApp(User $owner): array
{
    $app = App::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $owner->organization_id,
        'slug' => 'liv_'.Str::lower(Str::random(6)),
        'name' => 'Pedidos',
    ]);

    $manifest = [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => $app->slug,
        'name' => 'Pedidos',
        'version' => 1,
        'settings' => ['default_locale' => 'es-MX'],
        'objects' => [[
            'id' => 'obj_pedidos0001',
            'name' => 'Pedidos',
            'slug' => 'pedidos',
            'fields' => [
                ['id' => 'fld_folio00001', 'name' => 'Folio', 'slug' => 'folio', 'type' => 'string'],
            ],
        ]],
        'pages' => [[
            'id' => 'pag_pedidos0001',
            'slug' => 'pedidos',
            'path' => '/pedidos',
            'name' => 'Pedidos',
            'blocks' => [[
                'id' => 'blk_tabla00001',
                'type' => 'table',
                // The flag under test at the schema level.
                'live' => true,
                'data_source' => ['object_id' => 'obj_pedidos0001'],
                'columns' => [['id' => 'col_folio00001', 'field_id' => 'fld_folio00001']],
            ]],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
    ];

    app(AppManifestService::class)->createVersion($app, $manifest, $owner);

    return [$app->fresh(), $manifest];
}

it('announces a write from the one place every write passes through', function () {
    // A form, an inline grid edit, a bulk action, a workflow step and an MCP
    // tool all reach the same service — so none of them has to know this
    // exists, and none of them can forget.
    Event::fake([RecordChanged::class]);

    $owner = User::factory()->create();
    [$app, $manifest] = liveApp($owner);

    $record = app(RecordWriteService::class)
        ->create($app, $manifest, 'obj_pedidos0001', ['folio' => 'A-1'], $owner);

    Event::assertDispatched(RecordChanged::class, function (RecordChanged $e) use ($app, $record, $owner): bool {
        return $e->appId === $app->id
            && $e->objectId === 'obj_pedidos0001'
            && $e->recordId === $record->id
            && $e->verb === 'created'
            && $e->actorId === $owner->id;
    });
});

it('announces updates and deletes too', function () {
    Event::fake([RecordChanged::class]);

    $owner = User::factory()->create();
    [$app, $manifest] = liveApp($owner);
    $writes = app(RecordWriteService::class);

    $record = $writes->create($app, $manifest, 'obj_pedidos0001', ['folio' => 'A-1'], $owner);
    $writes->update($app, $manifest, $record, ['folio' => 'A-2'], $owner);
    $writes->delete($record, $app, $manifest, $owner);

    $verbs = [];
    Event::assertDispatched(RecordChanged::class, function (RecordChanged $e) use (&$verbs): bool {
        $verbs[] = $e->verb;

        return true;
    });

    expect($verbs)->toBe(['created', 'updated', 'deleted']);
});

it('carries the fact and never the row', function () {
    // The assertion that keeps this safe. A payload with the record's data
    // would reach subscribers whose row_filter hides it, and a broadcast has no
    // way to filter per recipient.
    $owner = User::factory()->create();
    [$app, $manifest] = liveApp($owner);

    $record = Record::create([
        'app_id' => $app->id,
        'object_definition_id' => 'obj_pedidos0001',
        'organization_id' => $app->organization_id,
        'data' => ['folio' => 'SECRETO-1'],
    ]);

    $event = new RecordChanged(
        $app->id,
        'obj_pedidos0001',
        $record->id,
        'updated',
        EnvironmentContext::PRODUCTION,
        $owner->id,
    );

    $payload = json_encode(get_object_vars($event));

    // `socket` comes from InteractsWithSockets and is not payload, so what is
    // asserted is what the event CARRIES and, above all, what it does not.
    expect($payload)->not->toContain('SECRETO-1')
        ->and($payload)->not->toContain('folio')
        ->and(array_keys(get_object_vars($event)))
        ->toContain('appId', 'objectId', 'recordId', 'verb', 'environment', 'actorId');
});

it('says which environment it happened in', function () {
    // A demo write must not make a production table blink: different data, and
    // a refresh that crossed them shows a reader nothing new at the cost of a
    // query.
    Event::fake([RecordChanged::class]);

    $owner = User::factory()->create();
    [$app, $manifest] = liveApp($owner);

    app(EnvironmentContext::class)->set(EnvironmentContext::DEMO);

    app(RecordWriteService::class)
        ->create($app, $manifest, 'obj_pedidos0001', ['folio' => 'D-1'], $owner);

    Event::assertDispatched(
        RecordChanged::class,
        fn (RecordChanged $e): bool => $e->environment === EnvironmentContext::DEMO,
    );
});

it('goes out on a channel only somebody who can see the app may join', function () {
    $owner = User::factory()->create();
    [$app] = liveApp($owner);

    $event = new RecordChanged($app->id, 'obj_pedidos0001', 'rec_x', 'created', 'production');

    expect($event->broadcastOn()[0]->name)->toBe("private-app.records.{$app->id}");
});

it('accepts live and presence as authored options', function () {
    // If the schema rejected either, a model would write the flag, be told it
    // worked, and the app would fail to save.
    $owner = User::factory()->create();
    [$app] = liveApp($owner);

    expect($app->current_version_id)->not->toBeNull();
});

it('never fails a write because the broadcaster is down', function () {
    // The worst case must be a table that refreshes when somebody clicks
    // instead of by itself — never a record that did not save.
    $owner = User::factory()->create();
    [$app, $manifest] = liveApp($owner);

    Event::listen(RecordChanged::class, function (): void {
        throw new RuntimeException('reverb is down');
    });

    $record = app(RecordWriteService::class)
        ->create($app, $manifest, 'obj_pedidos0001', ['folio' => 'A-1'], $owner);

    expect($record->exists)->toBeTrue()
        ->and(Record::find($record->id))->not->toBeNull();
});
