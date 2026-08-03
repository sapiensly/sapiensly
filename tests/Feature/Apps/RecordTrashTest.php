<?php

use App\Console\Commands\PruneTrashedRecords;
use App\Enums\MembershipRole;
use App\Models\App;
use App\Models\AppVersion;
use App\Models\Record;
use App\Models\RecordEvent;
use App\Models\User;
use App\Services\Apps\AppAccessResolver;
use App\Services\Records\BlockDataResolver;
use App\Services\Records\RecordQueryService;
use App\Services\Records\RecordWriteService;
use App\Support\Apps\EnvironmentContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The way back from a delete.
 *
 * Apps have had a version history and a rollback since the builder shipped;
 * records had neither, and bulk actions turned "one row by mistake" into "two
 * hundred rows by one click". The trash is the missing half.
 *
 * Most of what is asserted here is about what the trash is NOT: it is not a
 * second table, not a way around the environment scope or the role's filter,
 * and not a place rows live for ever.
 */
function trashApp(User $owner, array $overrides = []): App
{
    $app = App::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $owner->organization_id,
        'slug' => 'trash_'.Str::lower(Str::random(6)),
        'name' => 'Órdenes',
        'activity_retention_months' => 12,
    ]);

    $manifest = array_replace_recursive([
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => $app->slug,
        'name' => 'Órdenes',
        'version' => 1,
        'settings' => ['default_locale' => 'es-MX'],
        'objects' => [[
            'id' => 'obj_ordenes0001',
            'name' => 'Órdenes',
            'slug' => 'ordenes',
            'fields' => [
                ['id' => 'fld_folio00001', 'name' => 'Folio', 'slug' => 'folio', 'type' => 'string'],
            ],
        ]],
        'pages' => [],
        'permissions' => [
            'roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]],
        ],
    ], $overrides);

    $version = AppVersion::create([
        'id' => 'ver_'.strtolower((string) Str::ulid()),
        'app_id' => $app->id,
        'version_number' => 1,
        'created_by' => $owner->id,
        'manifest' => $manifest,
    ]);

    $app->update(['current_version_id' => $version->id]);

    return $app->fresh();
}

function trashManifest(App $app): array
{
    return AppVersion::find($app->current_version_id)->manifest;
}

function trashOrden(App $app, string $folio, string $environment = EnvironmentContext::PRODUCTION): Record
{
    return Record::create([
        'app_id' => $app->id,
        'object_definition_id' => 'obj_ordenes0001',
        'organization_id' => $app->organization_id,
        'user_id' => $app->user_id,
        'environment' => $environment,
        'data' => ['folio' => $folio],
    ]);
}

it('takes a deleted record out of every read at once', function () {
    // The point of the trait over a hand-filtered column: lists, counts,
    // charts, rollups and relation expansion all stop seeing it together. A
    // column somebody has to remember produces the one total that quietly
    // still includes deleted rows.
    $owner = User::factory()->create();
    $app = trashApp($owner);
    $manifest = trashManifest($app);
    $gone = trashOrden($app, 'A-1');
    trashOrden($app, 'A-2');

    app(RecordWriteService::class)->delete($gone, $app, $manifest, $owner);

    $query = ['object_id' => 'obj_ordenes0001'];
    $records = app(RecordQueryService::class);

    expect($records->query($app, $query, $manifest))->toHaveCount(1)
        ->and($records->count($app, $query, $manifest))->toBe(1)
        ->and($records->find($app, 'obj_ordenes0001', $gone->id, $manifest))->toBeNull()
        // Still there, though — that is the whole difference.
        ->and(Record::withTrashed()->find($gone->id))->not->toBeNull();
});

it('reads the trash only when asked, through the same scoped find', function () {
    $owner = User::factory()->create();
    $app = trashApp($owner);
    $manifest = trashManifest($app);
    $gone = trashOrden($app, 'A-1');

    app(RecordWriteService::class)->delete($gone, $app, $manifest, $owner);

    $found = app(RecordQueryService::class)
        ->find($app, 'obj_ordenes0001', $gone->id, $manifest, ['__trashed' => true]);

    expect($found?->id)->toBe($gone->id);
});

it('brings a record back, with its id and its relations intact', function () {
    // Restoring is why this is a column and not an archive table: copying a row
    // out and back gives it a new identity, and everything pointing at it keeps
    // pointing at nothing.
    $owner = User::factory()->create();
    $app = trashApp($owner);
    $manifest = trashManifest($app);
    $record = trashOrden($app, 'A-1');
    $originalId = $record->id;

    $writes = app(RecordWriteService::class);
    $writes->delete($record, $app, $manifest, $owner);
    $writes->restore(Record::withTrashed()->find($originalId), $app, $owner);

    expect(app(RecordQueryService::class)->find($app, 'obj_ordenes0001', $originalId, $manifest))
        ->not->toBeNull();
});

it('records the delete and the restore as separate events', function () {
    $owner = User::factory()->create();
    $app = trashApp($owner);
    $manifest = trashManifest($app);
    $record = trashOrden($app, 'A-1');

    $writes = app(RecordWriteService::class);
    $writes->delete($record, $app, $manifest, $owner);
    $writes->restore(Record::withTrashed()->find($record->id), $app, $owner);

    $kinds = RecordEvent::where('app_id', $app->id)->pluck('kind')->all();

    expect($kinds)->toContain(RecordEvent::KIND_DELETED)
        ->and($kinds)->toContain(RecordEvent::KIND_RESTORED);
});

it('does not fire creation automations when a record comes back', function () {
    // A restore is a return, not an arrival: re-firing record.created would
    // re-send the welcome mail and re-charge the invoice for something that
    // already happened once.
    $owner = User::factory()->create();
    $app = trashApp($owner);
    $manifest = trashManifest($app);
    $record = trashOrden($app, 'A-1');

    app(RecordWriteService::class)->delete($record, $app, $manifest, $owner);
    app(RecordWriteService::class)->restore(Record::withTrashed()->find($record->id), $app, $owner);

    expect(RecordEvent::where('app_id', $app->id)->where('kind', RecordEvent::KIND_CREATED)->count())
        ->toBe(0);
});

it('restores what was picked, over the same endpoint as the delete', function () {
    $owner = User::factory()->create();
    $app = trashApp($owner);
    $manifest = trashManifest($app);
    $a = trashOrden($app, 'A-1');
    $b = trashOrden($app, 'A-2');

    $this->actingAs($owner)->postJson("/r/{$app->slug}/bulk", [
        'object_id' => 'obj_ordenes0001',
        'action' => 'delete',
        'record_ids' => [$a->id, $b->id],
    ])->assertOk();

    $this->actingAs($owner)
        ->postJson("/r/{$app->slug}/bulk", [
            'object_id' => 'obj_ordenes0001',
            'action' => 'restore',
            'record_ids' => [$a->id, $b->id],
        ])
        ->assertOk()
        ->assertJson(['changed' => 2, 'skipped' => 0]);

    expect(Record::whereIn('id', [$a->id, $b->id])->count())->toBe(2);
});

it('will not restore a record that was never deleted', function () {
    // The trash read is `onlyTrashed`, so a live id is simply not in it. That
    // matters: otherwise "restore" would be a no-op the caller is told
    // succeeded.
    $owner = User::factory()->create();
    $app = trashApp($owner);
    $live = trashOrden($app, 'A-1');

    $this->actingAs($owner)
        ->postJson("/r/{$app->slug}/bulk", [
            'object_id' => 'obj_ordenes0001',
            'action' => 'restore',
            'record_ids' => [$live->id],
        ])
        ->assertOk()
        ->assertJson(['changed' => 0, 'skipped' => 1]);
});

it('empties a record for good, and says so before it goes', function () {
    $owner = User::factory()->create();
    $app = trashApp($owner);
    $manifest = trashManifest($app);
    $record = trashOrden($app, 'A-1');

    app(RecordWriteService::class)->delete($record, $app, $manifest, $owner);

    $this->actingAs($owner)
        ->postJson("/r/{$app->slug}/bulk", [
            'object_id' => 'obj_ordenes0001',
            'action' => 'purge',
            'record_ids' => [$record->id],
        ])
        ->assertOk()
        ->assertJson(['changed' => 1]);

    expect(Record::withTrashed()->find($record->id))->toBeNull()
        // The one entry that outlives the row.
        ->and(RecordEvent::where('app_id', $app->id)->where('kind', RecordEvent::KIND_PURGED)->count())
        ->toBe(1);
});

it('cannot reach the other environment from the trash either', function () {
    $owner = User::factory()->create();
    $app = trashApp($owner);
    $manifest = trashManifest($app);
    $live = trashOrden($app, 'A-1');

    app(RecordWriteService::class)->delete($live, $app, $manifest, $owner);

    // A demo session naming a production id restores nothing.
    $this->actingAs($owner)
        ->postJson("/r/{$app->slug}/bulk?env=demo", [
            'object_id' => 'obj_ordenes0001',
            'action' => 'restore',
            'record_ids' => [$live->id],
        ])
        ->assertOk()
        ->assertJson(['changed' => 0, 'skipped' => 1]);

    expect(Record::find($live->id))->toBeNull();
});

it('refuses the trash to a role that may read but not delete', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $org = mcpOrg();
    $owner = mcpMember($org);
    $app = trashApp($owner, ['permissions' => [
        'roles' => [
            ['id' => 'rol_lector0001', 'slug' => 'lector', 'name' => 'Lector', 'is_default' => true],
        ],
        'object_policies' => [[
            'object_id' => 'obj_ordenes0001',
            'role_id' => 'rol_lector0001',
            'actions' => ['read'],
        ]],
    ]]);
    $app->update(['visibility' => 'organization']);

    $record = trashOrden($app, 'A-1');
    app(RecordWriteService::class)->delete($record, $app, trashManifest($app), $owner);

    $this->actingAs(mcpMember($org, MembershipRole::Member))
        ->postJson("/r/{$app->slug}/bulk", [
            'object_id' => 'obj_ordenes0001',
            'action' => 'restore',
            'record_ids' => [$record->id],
        ])
        ->assertForbidden();
});

it('tells the table how much is in the trash, and only where it can be reached', function () {
    $owner = User::factory()->create();
    $app = trashApp($owner);
    $manifest = trashManifest($app);
    $record = trashOrden($app, 'A-1');
    trashOrden($app, 'A-2');

    app(RecordWriteService::class)->delete($record, $app, $manifest, $owner);

    $block = [
        'id' => 'blk_lista00001',
        'type' => 'table',
        'data_source' => ['object_id' => 'obj_ordenes0001'],
        'columns' => [['id' => 'col_folio00001', 'field_id' => 'fld_folio00001']],
    ];

    $this->actingAs($owner);
    $this->get("/r/{$app->slug}");

    $resolved = app(BlockDataResolver::class)->resolve($app, [$block], $manifest, [
        '__access' => app(AppAccessResolver::class)->resolve($app, $manifest, $owner),
        '__actor' => $owner,
        'params' => [],
    ]);

    expect($resolved['blk_lista00001']['trash_count'])->toBe(1)
        ->and($resolved['blk_lista00001']['trashed'])->toBeFalse()
        ->and($resolved['blk_lista00001']['rows'])->toHaveCount(1);
});

it('empties the trash of what has been in it past the window', function () {
    $owner = User::factory()->create();
    $app = trashApp($owner);
    $manifest = trashManifest($app);

    $old = trashOrden($app, 'A-1');
    $recent = trashOrden($app, 'A-2');

    app(RecordWriteService::class)->delete($old, $app, $manifest, $owner);
    app(RecordWriteService::class)->delete($recent, $app, $manifest, $owner);

    Record::withTrashed()->whereKey($old->id)->update([
        'deleted_at' => Carbon::now()->subDays(PruneTrashedRecords::WINDOW_DAYS + 1),
    ]);

    $this->artisan('records:prune-trash')->assertSuccessful();

    expect(Record::withTrashed()->find($old->id))->toBeNull()
        ->and(Record::withTrashed()->find($recent->id))->not->toBeNull();
});

it('reports without deleting when asked to', function () {
    $owner = User::factory()->create();
    $app = trashApp($owner);
    $record = trashOrden($app, 'A-1');

    app(RecordWriteService::class)->delete($record, $app, trashManifest($app), $owner);
    Record::withTrashed()->whereKey($record->id)->update([
        'deleted_at' => Carbon::now()->subDays(PruneTrashedRecords::WINDOW_DAYS + 1),
    ]);

    $this->artisan('records:prune-trash --dry-run')->assertSuccessful();

    expect(Record::withTrashed()->find($record->id))->not->toBeNull();
});
