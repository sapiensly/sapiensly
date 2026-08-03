<?php

use App\Enums\MembershipRole;
use App\Models\App;
use App\Models\AppVersion;
use App\Models\Record;
use App\Models\RecordEvent;
use App\Models\User;
use App\Support\Apps\EnvironmentContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/**
 * The same edit, applied to the rows somebody picked.
 *
 * The whole design is that a bulk action is not a shortcut PAST anything: every
 * record goes through the same write path as one done by hand, so validation,
 * the access filter, the workflow triggers and the activity trail all fire.
 * A feature whose behaviour depends on how many rows you selected is a feature
 * nobody can reason about — so most of what is asserted here is that the
 * batch behaves exactly like the singles it replaces.
 */
function bulkApp(User $owner, array $overrides = []): App
{
    $app = App::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $owner->organization_id,
        'slug' => 'bulk_'.Str::lower(Str::random(6)),
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
                [
                    'id' => 'fld_estado0001',
                    'name' => 'Estado',
                    'slug' => 'estado',
                    'type' => 'single_select',
                    'options' => [
                        ['value' => 'abierta', 'label' => 'Abierta'],
                        ['value' => 'cerrada', 'label' => 'Cerrada'],
                    ],
                ],
                [
                    'id' => 'fld_total00001',
                    'name' => 'Total',
                    'slug' => 'total',
                    'type' => 'rollup',
                    'aggregator' => 'count',
                ],
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

function orden(App $app, string $folio, string $estado = 'abierta'): Record
{
    return Record::create([
        'app_id' => $app->id,
        'object_definition_id' => 'obj_ordenes0001',
        'organization_id' => $app->organization_id,
        'user_id' => $app->user_id,
        'environment' => EnvironmentContext::PRODUCTION,
        'data' => ['folio' => $folio, 'estado' => $estado],
    ]);
}

it('applies one edit to every row that was picked', function () {
    $owner = User::factory()->create();
    $app = bulkApp($owner);
    $a = orden($app, 'A-1');
    $b = orden($app, 'A-2');
    $untouched = orden($app, 'A-3');

    $this->actingAs($owner)
        ->postJson("/r/{$app->slug}/bulk", [
            'object_id' => 'obj_ordenes0001',
            'action' => 'set',
            'record_ids' => [$a->id, $b->id],
            'field_id' => 'fld_estado0001',
            'value' => 'cerrada',
        ])
        ->assertOk()
        ->assertJson(['changed' => 2, 'skipped' => 0]);

    expect($a->fresh()->data['estado'])->toBe('cerrada')
        ->and($b->fresh()->data['estado'])->toBe('cerrada')
        ->and($untouched->fresh()->data['estado'])->toBe('abierta');
});

it('deletes the rows that were picked, and only those', function () {
    $owner = User::factory()->create();
    $app = bulkApp($owner);
    $a = orden($app, 'A-1');
    $keep = orden($app, 'A-2');

    $this->actingAs($owner)
        ->postJson("/r/{$app->slug}/bulk", [
            'object_id' => 'obj_ordenes0001',
            'action' => 'delete',
            'record_ids' => [$a->id],
        ])
        ->assertOk()
        ->assertJson(['changed' => 1]);

    expect(Record::find($a->id))->toBeNull()
        ->and(Record::find($keep->id))->not->toBeNull();
});

it('leaves a trail, exactly as the same edits done by hand would', function () {
    // The reason each record goes through RecordWriteService rather than one
    // bulk UPDATE: a change nobody can trace back is worse than a slow one.
    $owner = User::factory()->create();
    $app = bulkApp($owner);
    $a = orden($app, 'A-1');
    $b = orden($app, 'A-2');

    $this->actingAs($owner)->postJson("/r/{$app->slug}/bulk", [
        'object_id' => 'obj_ordenes0001',
        'action' => 'set',
        'record_ids' => [$a->id, $b->id],
        'field_id' => 'fld_estado0001',
        'value' => 'cerrada',
    ])->assertOk();

    expect(RecordEvent::where('app_id', $app->id)->where('kind', RecordEvent::KIND_UPDATED)->count())
        ->toBe(2);
});

it('reports the rows it skipped rather than counting them as done', function () {
    // "12 changed" when 3 were silently dropped is the kind of report somebody
    // acts on.
    $owner = User::factory()->create();
    $app = bulkApp($owner);
    $real = orden($app, 'A-1');

    $this->actingAs($owner)
        ->postJson("/r/{$app->slug}/bulk", [
            'object_id' => 'obj_ordenes0001',
            'action' => 'delete',
            'record_ids' => [$real->id, 'rec_01hzzzzzzzzzzzzzzzzzzzzzzz'],
        ])
        ->assertOk()
        ->assertJson(['changed' => 1, 'skipped' => 1]);
});

it('refuses a field the app works out for itself', function () {
    $owner = User::factory()->create();
    $app = bulkApp($owner);
    $a = orden($app, 'A-1');

    $this->actingAs($owner)
        ->postJson("/r/{$app->slug}/bulk", [
            'object_id' => 'obj_ordenes0001',
            'action' => 'set',
            'record_ids' => [$a->id],
            'field_id' => 'fld_total00001',
            'value' => 99,
        ])
        ->assertNotFound();
});

it('refuses a field that is not on the object at all', function () {
    $owner = User::factory()->create();
    $app = bulkApp($owner);
    $a = orden($app, 'A-1');

    $this->actingAs($owner)
        ->postJson("/r/{$app->slug}/bulk", [
            'object_id' => 'obj_ordenes0001',
            'action' => 'set',
            'record_ids' => [$a->id],
            'field_id' => 'fld_inventado01',
            'value' => 'x',
        ])
        ->assertNotFound();
});

it('bounds how much one call can touch', function () {
    // Not a performance number — it is how much somebody can undo, and there
    // is no undo for records.
    $owner = User::factory()->create();
    $app = bulkApp($owner);

    $this->actingAs($owner)
        ->postJson("/r/{$app->slug}/bulk", [
            'object_id' => 'obj_ordenes0001',
            'action' => 'delete',
            'record_ids' => array_fill(0, 201, 'rec_01hzzzzzzzzzzzzzzzzzzzzzzz'),
        ])
        ->assertStatus(422);
});

it('is closed to somebody who cannot see the app', function () {
    $owner = User::factory()->create();
    $app = bulkApp($owner);
    $a = orden($app, 'A-1');

    $this->actingAs(User::factory()->create())
        ->postJson("/r/{$app->slug}/bulk", [
            'object_id' => 'obj_ordenes0001',
            'action' => 'delete',
            'record_ids' => [$a->id],
        ])
        ->assertNotFound();

    expect(Record::find($a->id))->not->toBeNull();
});

it('refuses a role that may read but not delete', function () {
    // The bar is drawn from the `can` payload, so a reader never sees the
    // button — but the ids travel from a browser, and the button is not the
    // decision.
    $this->seed(RolesAndPermissionsSeeder::class);

    $org = mcpOrg();
    $owner = mcpMember($org);
    $app = bulkApp($owner, ['permissions' => [
        'roles' => [
            ['id' => 'rol_lector0001', 'slug' => 'lector', 'name' => 'Lector', 'is_default' => true],
        ],
        'object_policies' => [[
            'object_id' => 'obj_ordenes0001',
            'role_id' => 'rol_lector0001',
            'actions' => ['read'],
        ]],
    ]]);

    // Reachable by the organization at all — a private app 404s for everyone
    // but its owner, which is the runtime's rule and not this endpoint's.
    $app->update(['visibility' => 'organization']);

    $reader = mcpMember($org, MembershipRole::Member);
    $a = orden($app, 'A-1');

    $this->actingAs($reader)
        ->postJson("/r/{$app->slug}/bulk", [
            'object_id' => 'obj_ordenes0001',
            'action' => 'delete',
            'record_ids' => [$a->id],
        ])
        ->assertForbidden();

    expect(Record::find($a->id))->not->toBeNull();
});

it('cannot reach the other environment from the one it was called in', function () {
    // The environment is a mode, not a parameter: a demo session naming a
    // production id must not reach it.
    $owner = User::factory()->create();
    $app = bulkApp($owner);
    $live = orden($app, 'A-1');

    $this->actingAs($owner)
        ->postJson("/r/{$app->slug}/bulk?env=demo", [
            'object_id' => 'obj_ordenes0001',
            'action' => 'delete',
            'record_ids' => [$live->id],
        ])
        ->assertOk()
        ->assertJson(['changed' => 0, 'skipped' => 1]);

    expect(Record::find($live->id))->not->toBeNull();
});
