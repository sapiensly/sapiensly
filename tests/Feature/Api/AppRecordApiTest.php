<?php

use App\Models\App;
use App\Models\Organization;
use App\Models\Record;
use App\Models\User;
use App\Services\Apps\ApiKeyService;
use App\Services\Manifest\AppManifestService;
use Illuminate\Support\Str;

/**
 * The REST data API, exercised as the holder of a leaked key rather than as its
 * author. Two gates must hold on every call — the key's own scope, and the
 * capabilities of the app role it acts as — and neither may be the only one.
 */
function apiId(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

beforeEach(function () {
    $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(6))]);
    $this->owner = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $this->org->id]);

    $this->testApp = App::create([
        'user_id' => $this->owner->id, 'organization_id' => $this->org->id,
        'slug' => 'ventas', 'name' => 'Ventas', 'visibility' => 'organization',
    ]);

    $this->ids = [
        'objPedidos' => apiId('obj'),
        'objSecretos' => apiId('obj'),
        'fldCliente' => apiId('fld'),
        'fldMargen' => apiId('fld'),
        'fldNota' => apiId('fld'),
        'rolIntegracion' => apiId('rol'),
        'rolAdmin' => apiId('rol'),
    ];

    $manifest = [
        'schema_version' => '1.0.0',
        'id' => $this->testApp->id,
        'slug' => 'ventas',
        'name' => 'Ventas',
        'version' => 1,
        'objects' => [
            [
                'id' => $this->ids['objPedidos'], 'slug' => 'pedidos', 'name' => 'Pedido',
                'fields' => [
                    ['id' => $this->ids['fldCliente'], 'slug' => 'cliente', 'name' => 'Cliente', 'type' => 'string'],
                    ['id' => $this->ids['fldMargen'], 'slug' => 'margen', 'name' => 'Margen', 'type' => 'number'],
                ],
            ],
            [
                'id' => $this->ids['objSecretos'], 'slug' => 'secretos', 'name' => 'Secreto',
                'fields' => [
                    ['id' => $this->ids['fldNota'], 'slug' => 'nota', 'name' => 'Nota', 'type' => 'string'],
                ],
            ],
        ],
        'pages' => [],
        'permissions' => [
            'roles' => [
                ['id' => $this->ids['rolAdmin'], 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true],
                ['id' => $this->ids['rolIntegracion'], 'slug' => 'integracion', 'name' => 'Integración', 'is_default' => false],
            ],
            'object_policies' => [
                [
                    'object_id' => $this->ids['objPedidos'], 'role_id' => $this->ids['rolIntegracion'],
                    'actions' => ['read', 'create'],
                    // The margin is the app's business, not the integration's.
                    'field_restrictions' => ['hidden' => [$this->ids['fldMargen']]],
                ],
                [
                    'object_id' => $this->ids['objSecretos'], 'role_id' => $this->ids['rolAdmin'],
                    'actions' => ['read', 'create', 'update', 'delete'],
                ],
            ],
        ],
    ];

    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->owner);
    $this->testApp->refresh();

    Record::create([
        'app_id' => $this->testApp->id, 'object_definition_id' => $this->ids['objPedidos'],
        'data' => ['cliente' => 'Acme', 'margen' => 42],
    ]);
    Record::create([
        'app_id' => $this->testApp->id, 'object_definition_id' => $this->ids['objSecretos'],
        'data' => ['nota' => 'nómina'],
    ]);
});

/** Mint a key and return its bearer header. */
function apiKeyFor($test, string $role = 'integracion', ?array $scopes = null): array
{
    $minted = app(ApiKeyService::class)->mint(
        $test->testApp->refresh(),
        'Integration',
        $role,
        $scopes,
        $test->owner,
    );

    return ['Authorization' => 'Bearer '.$minted['token']];
}

it('refuses a request with no key, a junk key, or a revoked one', function () {
    $this->getJson('/api/apps/v1/objects')->assertStatus(401);
    $this->getJson('/api/apps/v1/objects', ['Authorization' => 'Bearer sap_nope_nope'])->assertStatus(401);

    $minted = app(ApiKeyService::class)->mint($this->testApp->refresh(), 'K', 'integracion', null, $this->owner);
    $headers = ['Authorization' => 'Bearer '.$minted['token']];
    $this->getJson('/api/apps/v1/objects', $headers)->assertOk();

    app(ApiKeyService::class)->revoke($minted['key']);
    $this->getJson('/api/apps/v1/objects', $headers)->assertStatus(401);
});

it('refuses an expired key', function () {
    $minted = app(ApiKeyService::class)->mint(
        $this->testApp->refresh(), 'Caducada', 'integracion', null, $this->owner,
        new DateTimeImmutable('-1 hour'),
    );

    $this->getJson('/api/apps/v1/objects', ['Authorization' => 'Bearer '.$minted['token']])
        ->assertStatus(401);
});

it('lists only what the key may read, without the fields its role hides', function () {
    $response = $this->getJson('/api/apps/v1/objects', apiKeyFor($this))->assertOk();

    // `secretos` is granted to admin, not to this key's role — so it is not
    // merely unreadable, it is invisible.
    expect(collect($response->json('objects'))->pluck('slug')->all())->toBe(['pedidos'])
        ->and(collect($response->json('objects.0.fields'))->pluck('slug')->all())->toBe(['cliente'])
        ->and($response->json('objects.0.actions'))->toBe(['read', 'create']);
});

it('reads records with the hidden field stripped', function () {
    $response = $this->getJson('/api/apps/v1/objects/pedidos/records', apiKeyFor($this))->assertOk();

    expect($response->json('total'))->toBe(1)
        ->and($response->json('data.0.data.cliente'))->toBe('Acme')
        ->and($response->json('data.0.data'))->not->toHaveKey('margen');
});

it('refuses an object the key\'s role was never granted', function () {
    $this->getJson('/api/apps/v1/objects/secretos/records', apiKeyFor($this))
        ->assertStatus(403)
        ->assertJsonPath('error', 'forbidden');
});

it('creates a record, and refuses the actions the role lacks', function () {
    $headers = apiKeyFor($this);

    $created = $this->postJson('/api/apps/v1/objects/pedidos/records', [
        'data' => ['cliente' => 'Globex'],
    ], $headers)->assertStatus(201);

    expect(Record::where('object_definition_id', $this->ids['objPedidos'])->count())->toBe(2);

    // The role grants read+create only: update and delete are refused even
    // though the key itself is unscoped.
    $id = $created->json('data.id');
    $this->patchJson("/api/apps/v1/objects/pedidos/records/{$id}", ['data' => ['cliente' => 'X']], $headers)
        ->assertStatus(403);
    $this->deleteJson("/api/apps/v1/objects/pedidos/records/{$id}", [], $headers)
        ->assertStatus(403);
});

it('lets a key narrow itself below what its role allows', function () {
    // The role could create; this key is issued for reading only.
    $headers = apiKeyFor($this, scopes: ['pedidos' => ['read']]);

    $this->getJson('/api/apps/v1/objects/pedidos/records', $headers)->assertOk();
    $this->postJson('/api/apps/v1/objects/pedidos/records', ['data' => ['cliente' => 'X']], $headers)
        ->assertStatus(403);
});

it('never lets a key scope widen past its role', function () {
    // A key that ASKS for everything on an object its role cannot touch still
    // gets nothing — the scope is a grant within the ceiling, not a bypass.
    $headers = apiKeyFor($this, scopes: ['secretos' => ['read', 'create', 'update', 'delete']]);

    $this->getJson('/api/apps/v1/objects/secretos/records', $headers)->assertStatus(403);
    expect(collect($this->getJson('/api/apps/v1/objects', $headers)->json('objects'))->pluck('slug')->all())
        ->toBe([]);
});

it('validates writes exactly as the app would from a form', function () {
    $this->postJson('/api/apps/v1/objects/pedidos/records', [
        'data' => ['cliente' => 'Globex', 'inventado' => 'x'],
    ], apiKeyFor($this))
        ->assertStatus(422)
        ->assertJsonPath('error', 'validation_failed');
});

it('refuses to mint a key for a role or an object that does not exist', function () {
    expect(fn () => app(ApiKeyService::class)->mint($this->testApp->refresh(), 'K', 'fantasma'))
        ->toThrow(InvalidArgumentException::class, 'not a role in this app')
        ->and(fn () => app(ApiKeyService::class)->mint(
            $this->testApp->refresh(), 'K', 'integracion', ['fantasma' => ['read']],
        ))->toThrow(InvalidArgumentException::class, 'not an object in this app');
});

it('cannot reach another app\'s data', function () {
    $other = App::create([
        'user_id' => $this->owner->id, 'organization_id' => $this->org->id,
        'slug' => 'otra', 'name' => 'Otra', 'visibility' => 'organization',
    ]);
    $otherObj = apiId('obj');
    app(AppManifestService::class)->createVersion($other, [
        'schema_version' => '1.0.0', 'id' => $other->id, 'slug' => 'otra', 'name' => 'Otra', 'version' => 1,
        'objects' => [[
            'id' => $otherObj, 'slug' => 'pedidos', 'name' => 'Pedido',
            'fields' => [['id' => apiId('fld'), 'slug' => 'cliente', 'name' => 'Cliente', 'type' => 'string']],
        ]],
        'pages' => [],
        'permissions' => ['roles' => [['id' => apiId('rol'), 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
    ], $this->owner);

    Record::create(['app_id' => $other->id, 'object_definition_id' => $otherObj, 'data' => ['cliente' => 'Ajena']]);

    // The key names no app — its own app is implied — so the OTHER app's
    // identically-slugged object is simply not the one it addresses.
    $response = $this->getJson('/api/apps/v1/objects/pedidos/records', apiKeyFor($this))->assertOk();

    expect($response->json('total'))->toBe(1)
        ->and($response->json('data.0.data.cliente'))->toBe('Acme');
});

it('stores only a hash, so the token can never be read back', function () {
    $minted = app(ApiKeyService::class)->mint($this->testApp->refresh(), 'K', 'integracion', null, $this->owner);

    expect($minted['key']->token_hash)->toBe(hash('sha256', $minted['token']))
        ->and($minted['key']->toArray())->not->toHaveKey('token_hash')
        ->and($minted['token'])->toStartWith('sap_');
});

it('filters by a field slug, and never by one the role hides', function () {
    Record::create([
        'app_id' => $this->testApp->id, 'object_definition_id' => $this->ids['objPedidos'],
        'data' => ['cliente' => 'Globex', 'margen' => 7],
    ]);
    $headers = apiKeyFor($this);

    $this->getJson('/api/apps/v1/objects/pedidos/records?filter[cliente]=Globex', $headers)
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.data.cliente', 'Globex');

    // `margen` is hidden for this role. Filtering on it would let a caller
    // binary-search a value it may not read, so the filter is dropped and the
    // query answers unfiltered rather than leaking one bit at a time.
    $this->getJson('/api/apps/v1/objects/pedidos/records?filter[margen][gte]=40', $headers)
        ->assertOk()
        ->assertJsonPath('total', 2);
});

it('sorts by a slug, descending with a leading minus', function () {
    Record::create([
        'app_id' => $this->testApp->id, 'object_definition_id' => $this->ids['objPedidos'],
        'data' => ['cliente' => 'Zebra', 'margen' => 1],
    ]);
    $headers = apiKeyFor($this);

    $ascending = $this->getJson('/api/apps/v1/objects/pedidos/records?sort=cliente', $headers)->assertOk();
    expect($ascending->json('data.0.data.cliente'))->toBe('Acme');

    $descending = $this->getJson('/api/apps/v1/objects/pedidos/records?sort=-cliente', $headers)->assertOk();
    expect($descending->json('data.0.data.cliente'))->toBe('Zebra');
});

it('ignores a filter or sort naming a field that does not exist', function () {
    $this->getJson('/api/apps/v1/objects/pedidos/records?filter[fantasma]=x&sort=-tampoco', apiKeyFor($this))
        ->assertOk()
        ->assertJsonPath('total', 1);
});
