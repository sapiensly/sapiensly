<?php

use App\Enums\MembershipRole;
use App\Models\App;
use App\Models\Record;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Support\Apps\EnvironmentContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/**
 * Which record carries this code.
 *
 * The other half of scanning. Reading the barcode is the easy part; what
 * somebody wants is the pallet, the asset or the order OPEN on their screen —
 * without this the scan produces a string they then go and search for by hand,
 * which is the job they were trying not to do.
 *
 * Addressed by the FIELD, like the relation options endpoint: a caller holds a
 * field id and cannot name an object of its own choosing.
 */
function lookupApp(User $owner, array $overrides = []): App
{
    $app = App::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $owner->organization_id,
        'slug' => 'lkp_'.Str::lower(Str::random(6)),
        'name' => 'Inventario',
    ]);

    $manifest = array_replace_recursive([
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => $app->slug,
        'name' => 'Inventario',
        'version' => 1,
        'settings' => ['default_locale' => 'es-MX'],
        'objects' => [[
            'id' => 'obj_articulo0001',
            'name' => 'Artículos',
            'slug' => 'articulos',
            'fields' => [
                ['id' => 'fld_sku00000001', 'name' => 'SKU', 'slug' => 'sku', 'type' => 'string', 'capture' => 'barcode'],
                ['id' => 'fld_nombre000001', 'name' => 'Nombre', 'slug' => 'nombre', 'type' => 'string'],
            ],
        ]],
        'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
    ], $overrides);

    app(AppManifestService::class)->createVersion($app, $manifest, $owner);

    return $app->fresh();
}

function article(App $app, string $sku, string $environment = EnvironmentContext::PRODUCTION): Record
{
    return Record::create([
        'app_id' => $app->id,
        'object_definition_id' => 'obj_articulo0001',
        'organization_id' => $app->organization_id,
        'environment' => $environment,
        'data' => ['sku' => $sku, 'nombre' => 'Caja'],
    ]);
}

it('answers with the one record carrying the code', function () {
    $owner = User::factory()->create();
    $app = lookupApp($owner);
    $found = article($app, '7501234567890');
    article($app, '7509999999999');

    $this->actingAs($owner)
        ->getJson("/r/{$app->slug}/fields/fld_sku00000001/lookup?value=7501234567890")
        ->assertOk()
        ->assertJson(['id' => $found->id, 'ambiguous' => false]);
});

it('refuses to guess when two records share a code', function () {
    // Opening whichever sorted first and letting somebody act on it is how a
    // picker works on the wrong pallet.
    $owner = User::factory()->create();
    $app = lookupApp($owner);
    article($app, 'DUP-1');
    article($app, 'DUP-1');

    $this->actingAs($owner)
        ->getJson("/r/{$app->slug}/fields/fld_sku00000001/lookup?value=DUP-1")
        ->assertOk()
        ->assertJson(['id' => null, 'ambiguous' => true]);
});

it('says nothing was found rather than inventing something', function () {
    $owner = User::factory()->create();
    $app = lookupApp($owner);
    article($app, 'A-1');

    $this->actingAs($owner)
        ->getJson("/r/{$app->slug}/fields/fld_sku00000001/lookup?value=NO-EXISTE")
        ->assertOk()
        ->assertJson(['id' => null, 'ambiguous' => false]);
});

it('cannot reach the other environment', function () {
    // A scan in the sandbox must not open a real pallet, and the reverse.
    $owner = User::factory()->create();
    $app = lookupApp($owner);
    article($app, 'REAL-1');

    $this->actingAs($owner)
        ->getJson("/r/{$app->slug}/fields/fld_sku00000001/lookup?value=REAL-1&env=demo")
        ->assertOk()
        ->assertJson(['id' => null]);
});

it('will not look up a field on an object the role cannot read', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $org = mcpOrg();
    $owner = mcpMember($org);
    $app = lookupApp($owner, ['permissions' => [
        'roles' => [['id' => 'rol_lector0001', 'slug' => 'lector', 'name' => 'Lector', 'is_default' => true]],
        // A policy that grants NOTHING on this object. An empty policy LIST
        // would mean "no restrictions", which is the opposite.
        'object_policies' => [[
            'object_id' => 'obj_articulo0001',
            'role_id' => 'rol_lector0001',
            'actions' => [],
        ]],
    ]]);
    $app->update(['visibility' => 'organization']);

    article($app, 'SECRETO');

    $this->actingAs(mcpMember($org, MembershipRole::Member))
        ->getJson("/r/{$app->slug}/fields/fld_sku00000001/lookup?value=SECRETO")
        ->assertNotFound();
});

it('is closed to somebody who cannot see the app', function () {
    $owner = User::factory()->create();
    $app = lookupApp($owner);
    article($app, 'A-1');

    $this->actingAs(User::factory()->create())
        ->getJson("/r/{$app->slug}/fields/fld_sku00000001/lookup?value=A-1")
        ->assertNotFound();
});

it('accepts scan_to_find as an authored action', function () {
    // If the schema rejected it, a model would write the button, be told it
    // worked, and the app would fail to save.
    $owner = User::factory()->create();

    $app = lookupApp($owner, ['pages' => [[
        'id' => 'pag_buscar00001',
        'slug' => 'buscar',
        'path' => '/buscar',
        'name' => 'Buscar',
        'blocks' => [[
            'id' => 'blk_scan00000001',
            'type' => 'button',
            'label' => 'Escanear artículo',
            'on_click' => [[
                'type' => 'scan_to_find',
                'field_id' => 'fld_sku00000001',
                'page_slug' => 'articulo_detail',
            ]],
        ]],
    ]]]);

    expect($app->current_version_id)->not->toBeNull();
});
