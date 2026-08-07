<?php

use App\Models\App;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use Illuminate\Support\Str;

/**
 * The rule that a stale client cannot bypass.
 *
 * `settings.offline` is read in two places and only one of them is trusted.
 * The client decides whether to HOLD a write, because the server never sees the
 * request that was not made — but whether a PAGE may be written to the device
 * is settled here, with `no-store`, which the service worker already refuses to
 * store and which a worker installed last month honours exactly as well as one
 * installed today.
 */
function oph_id(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

function oph_build(array $offline): array
{
    $user = User::factory()->create(['email_verified_at' => now()]);

    $app = App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->organization_id,
        'slug' => 'campo',
        'name' => 'Servicio Campo',
    ]);

    $ordenes = oph_id('obj');
    $nominas = oph_id('obj');
    $folio = oph_id('fld');
    $monto = oph_id('fld');
    $role = oph_id('rol');

    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => 'campo',
        'name' => 'Servicio Campo',
        'version' => 1,
        'objects' => [
            ['id' => $ordenes, 'slug' => 'ordenes', 'name' => 'Órdenes', 'fields' => [
                ['id' => $folio, 'slug' => 'folio', 'name' => 'Folio', 'type' => 'string'],
            ]],
            ['id' => $nominas, 'slug' => 'nominas', 'name' => 'Nóminas', 'fields' => [
                ['id' => $monto, 'slug' => 'monto', 'name' => 'Monto', 'type' => 'currency'],
            ]],
        ],
        'pages' => [
            [
                'id' => oph_id('pag'), 'slug' => 'ordenes', 'name' => 'Órdenes', 'path' => '/ordenes',
                'blocks' => [[
                    'id' => oph_id('blk'), 'type' => 'table',
                    'data_source' => ['object_id' => $ordenes],
                    'columns' => [['id' => oph_id('col'), 'field_id' => $folio]],
                ]],
            ],
            [
                'id' => oph_id('pag'), 'slug' => 'nomina', 'name' => 'Nómina', 'path' => '/nomina',
                'blocks' => [[
                    'id' => oph_id('blk'), 'type' => 'table',
                    'data_source' => ['object_id' => $nominas],
                    'columns' => [['id' => oph_id('col'), 'field_id' => $monto]],
                ]],
            ],
        ],
        'permissions' => [
            'roles' => [['id' => $role, 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]],
            'object_policies' => [
                ['object_id' => $ordenes, 'role_id' => $role, 'actions' => ['create', 'read', 'update', 'delete']],
                ['object_id' => $nominas, 'role_id' => $role, 'actions' => ['create', 'read', 'update', 'delete']],
            ],
        ],
        // Omitted rather than empty: an empty PHP array encodes as a JSON
        // array, and the schema wants an object.
        ...($offline === [] ? [] : ['settings' => ['offline' => $offline]]),
    ], $user);

    return [$user, $app];
}

it('lets an ordinary page be cached', function () {
    [$user] = oph_build([]);

    $response = $this->actingAs($user)->get('/r/campo/ordenes')->assertOk();

    expect($response->headers->get('Cache-Control'))->not->toContain('no-store');
});

it('refuses to let any page of a disabled app be cached', function () {
    [$user] = oph_build(['enabled' => false]);

    foreach (['/r/campo/ordenes', '/r/campo/nomina'] as $url) {
        expect($this->actingAs($user)->get($url)->assertOk()->headers->get('Cache-Control'))
            ->toContain('no-store');
    }
});

it('refuses only the pages that read the excluded object', function () {
    // The point of doing it per object: the field app keeps working offline,
    // and the one page showing salaries does not follow the technician home.
    [$user] = oph_build(['exclude_objects' => ['nominas']]);

    expect($this->actingAs($user)->get('/r/campo/ordenes')->assertOk()->headers->get('Cache-Control'))
        ->not->toContain('no-store');

    expect($this->actingAs($user)->get('/r/campo/nomina')->assertOk()->headers->get('Cache-Control'))
        ->toContain('no-store');
});

it('tells the client what it may hold', function () {
    [$user] = oph_build(['exclude_objects' => ['nominas']]);

    $this->actingAs($user)
        ->get('/r/campo/ordenes')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('offline.enabled', true)
            ->has('offline.excluded_object_ids', 1));
});
