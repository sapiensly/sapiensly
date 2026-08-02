<?php

use App\Models\App;
use App\Models\AppUserRole;
use App\Models\AppVersion;
use App\Models\Record;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The records a relation field can point at.
 *
 * Without this endpoint a relation is unfillable: the app models the link,
 * builds the child list and the rollups from it, and then offers a text box for
 * an id nobody has. What matters here is that it answers with NAMES, and that
 * it never answers with more than the table beside it would show.
 */
function optionsApp(User $owner, array $overrides = []): App
{
    $app = App::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $owner->organization_id,
        'slug' => 'taller-'.Str::lower(Str::random(6)),
        'name' => 'Taller',
    ]);

    $manifest = array_replace_recursive([
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => $app->slug,
        'name' => 'Taller',
        'version' => 1,
        'settings' => ['default_locale' => 'es-MX'],
        'objects' => [[
            'id' => 'obj_ordenes',
            'name' => 'Órdenes',
            'slug' => 'ordenes',
            'fields' => [
                ['id' => 'fld_folio', 'name' => 'Folio', 'slug' => 'folio', 'type' => 'string'],
                ['id' => 'fld_vehiculo', 'name' => 'vehículo', 'slug' => 'vehiculo', 'type' => 'relation', 'cardinality' => 'many_to_one', 'target_object_id' => 'obj_vehiculos'],
            ],
        ], [
            'id' => 'obj_vehiculos',
            'name' => 'Vehículos',
            'slug' => 'vehiculos',
            'primary_display_field_id' => 'fld_placas',
            'fields' => [
                ['id' => 'fld_placas', 'name' => 'Placas', 'slug' => 'placas', 'type' => 'string'],
                ['id' => 'fld_marca', 'name' => 'Marca', 'slug' => 'marca', 'type' => 'string'],
            ],
        ]],
        'pages' => [],
        'permissions' => [
            'roles' => [['id' => 'rol_admin', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]],
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

function vehicle(App $app, string $plates, string $make = 'Nissan'): Record
{
    return Record::create([
        'app_id' => $app->id,
        'object_definition_id' => 'obj_vehiculos',
        'organization_id' => $app->organization_id,
        'user_id' => $app->user_id,
        'data' => ['placas' => $plates, 'marca' => $make],
    ]);
}

it('answers with the name a person recognises, not the id', function () {
    $owner = User::factory()->create();
    $app = optionsApp($owner);
    vehicle($app, 'ABC-123');

    $response = $this->actingAs($owner)
        ->getJson("/r/{$app->slug}/fields/fld_vehiculo/options")
        ->assertOk();

    expect($response->json('options'))->toHaveCount(1)
        ->and($response->json('options.0.label'))->toBe('ABC-123')
        ->and($response->json('options.0.id'))->toStartWith('rec_');
});

it('narrows to what was typed', function () {
    $owner = User::factory()->create();
    $app = optionsApp($owner);
    vehicle($app, 'ABC-123', 'Nissan');
    vehicle($app, 'XYZ-789', 'Toyota');

    $response = $this->actingAs($owner)
        ->getJson("/r/{$app->slug}/fields/fld_vehiculo/options?q=Toyota")
        ->assertOk();

    expect($response->json('options'))->toHaveCount(1)
        ->and($response->json('options.0.label'))->toBe('XYZ-789');
});

it('resolves ids so an edit form opens showing a name', function () {
    // The stored value is an id. Without this the box would open blank on
    // every edit and quietly drop the link when saved.
    $owner = User::factory()->create();
    $app = optionsApp($owner);
    $one = vehicle($app, 'ABC-123');
    vehicle($app, 'XYZ-789');

    $response = $this->actingAs($owner)
        ->getJson("/r/{$app->slug}/fields/fld_vehiculo/options?ids={$one->id}")
        ->assertOk();

    expect($response->json('options'))->toHaveCount(1)
        ->and($response->json('options.0.label'))->toBe('ABC-123');
});

it('ignores anything in ids that is not a record id', function () {
    $owner = User::factory()->create();
    $app = optionsApp($owner);
    vehicle($app, 'ABC-123');

    // The parameter reaches a query. Shapes that are not record ids are
    // dropped rather than passed along to find out what happens.
    $this->actingAs($owner)
        ->getJson("/r/{$app->slug}/fields/fld_vehiculo/options?ids=1%20OR%201=1,../../etc")
        ->assertOk()
        ->assertJsonCount(0, 'options');
});

it('says when there is more than it returned', function () {
    $owner = User::factory()->create();
    $app = optionsApp($owner);
    for ($i = 0; $i < 22; $i++) {
        vehicle($app, 'PL-'.$i);
    }

    $response = $this->actingAs($owner)
        ->getJson("/r/{$app->slug}/fields/fld_vehiculo/options")
        ->assertOk();

    // 20 back and a flag: a box that showed twenty of twenty-two with no sign
    // of the rest is a box people believe.
    expect($response->json('options'))->toHaveCount(20)
        ->and($response->json('truncated'))->toBeTrue();
});

it('shows nothing to a role that may not read the object', function () {
    // A picker that listed rows the table hides would be the quietest possible
    // way around a permission.
    $owner = User::factory()->create();
    $app = optionsApp($owner, ['permissions' => [
        'roles' => [
            ['id' => 'rol_admin', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => false],
            ['id' => 'rol_viewer', 'slug' => 'viewer', 'name' => 'Viewer', 'is_default' => true],
        ],
        'object_policies' => [
            ['role_id' => 'rol_admin', 'object_id' => 'obj_vehiculos', 'actions' => ['create', 'read', 'update', 'delete']],
            ['role_id' => 'rol_viewer', 'object_id' => 'obj_vehiculos', 'actions' => []],
        ],
    ]]);
    vehicle($app, 'ABC-123');

    $member = User::factory()->create(['organization_id' => $owner->organization_id]);
    AppUserRole::create([
        'id' => 'aur_'.strtolower((string) Str::ulid()),
        'app_id' => $app->id,
        'assigned_user_id' => $member->id,
        'role_slug' => 'viewer',
    ]);

    $this->actingAs($member)
        ->getJson("/r/{$app->slug}/fields/fld_vehiculo/options")
        ->assertNotFound();
});

it('is not reachable for an app the caller cannot see', function () {
    $owner = User::factory()->create();
    $app = optionsApp($owner);
    vehicle($app, 'ABC-123');

    $this->actingAs(User::factory()->create())
        ->getJson("/r/{$app->slug}/fields/fld_vehiculo/options")
        ->assertNotFound();
});

it('falls back to the first text field when no display field is set', function () {
    $owner = User::factory()->create();
    $app = optionsApp($owner);
    $manifest = $app->currentVersion->manifest;
    unset($manifest['objects'][1]['primary_display_field_id']);
    $app->currentVersion->update(['manifest' => $manifest]);
    vehicle($app, 'ABC-123');

    $response = $this->actingAs($owner)
        ->getJson("/r/{$app->slug}/fields/fld_vehiculo/options")
        ->assertOk();

    expect($response->json('options.0.label'))->toBe('ABC-123');
});

it('refuses a field that is not a relation', function () {
    // The id reaches a target lookup. A plain text field has nothing to point
    // at, and answering with SOMETHING would be worse than answering nothing.
    $owner = User::factory()->create();
    $app = optionsApp($owner);
    vehicle($app, 'ABC-123');

    $this->actingAs($owner)
        ->getJson("/r/{$app->slug}/fields/fld_folio/options")
        ->assertNotFound();
});
