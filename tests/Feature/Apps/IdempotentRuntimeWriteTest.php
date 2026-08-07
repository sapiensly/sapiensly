<?php

use App\Facades\TenantCache;
use App\Http\Middleware\IdempotentRuntimeWrite;
use App\Models\App;
use App\Models\Record;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * A write sent twice is performed once.
 *
 * This is what lets the offline queue retry at all. A phone that loses signal
 * mid-request cannot tell "never arrived" from "arrived, answer lost", and
 * those need opposite handling — so without a key the client must choose
 * between a duplicate work order and a lost one. Both are wrong.
 */
function irw_id(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);

    $this->testApp = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'slug' => 'campo',
        'name' => 'Servicio Campo',
    ]);

    $this->objId = irw_id('obj');
    $rolId = irw_id('rol');

    app(AppManifestService::class)->createVersion($this->testApp, [
        'schema_version' => '1.0.0',
        'id' => $this->testApp->id,
        'slug' => 'campo',
        'name' => 'Servicio Campo',
        'version' => 1,
        'objects' => [[
            'id' => $this->objId,
            'slug' => 'ordenes',
            'name' => 'Orden',
            'fields' => [
                ['id' => irw_id('fld'), 'slug' => 'folio', 'name' => 'Folio', 'type' => 'string'],
            ],
        ]],
        'pages' => [],
        'permissions' => [
            'roles' => [['id' => $rolId, 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]],
            'object_policies' => [[
                'object_id' => $this->objId, 'role_id' => $rolId,
                'actions' => ['create', 'read', 'update', 'delete'],
            ]],
        ],
    ], $this->user);
});

/** @return TestResponse */
function irw_create(string $folio, ?string $key = null)
{
    return test()->actingAs(test()->user)
        ->postJson('/r/campo/actions', [
            'actions' => [[
                'type' => 'create_record',
                'object_id' => test()->objId,
                'values' => ['folio' => $folio],
            ]],
        ], $key === null ? [] : ['Idempotency-Key' => $key]);
}

function irw_records(): int
{
    return Record::query()->where('app_id', test()->testApp->id)->count();
}

it('writes once when the same request arrives twice', function () {
    irw_create('OT-1', 'key-abc')->assertOk();
    irw_create('OT-1', 'key-abc')->assertOk();

    expect(irw_records())->toBe(1);
});

it('hands back the first attempt’s own answer, not a fresh one', function () {
    // Verbatim, including the created record's id: the caller asked the same
    // question and a different answer would send it looking for a record that
    // was never created.
    $first = irw_create('OT-1', 'key-abc')->assertOk();
    $replay = irw_create('OT-1', 'key-abc')->assertOk();

    expect($replay->json())->toBe($first->json())
        ->and($replay->headers->get('Idempotent-Replay'))->toBe('true');
});

it('treats a different key as a different write', function () {
    // Two work orders raised on the same visit are two work orders.
    irw_create('OT-1', 'key-abc')->assertOk();
    irw_create('OT-2', 'key-def')->assertOk();

    expect(irw_records())->toBe(2);
});

it('leaves an ordinary online write alone', function () {
    // No key means a live page, which has no replay to protect against.
    irw_create('OT-1')->assertOk();
    irw_create('OT-1')->assertOk();

    expect(irw_records())->toBe(2);
});

it('does not remember a failed write', function () {
    // A rejection must stay retryable: freezing a transient fault into a
    // permanent stored answer would turn one bad minute into lost work.
    test()->actingAs($this->user)
        ->postJson('/r/campo/actions', [
            'actions' => [['type' => 'create_record', 'object_id' => 'obj_does_not_exist', 'values' => []]],
        ], ['Idempotency-Key' => 'key-fail'])
        ->assertStatus(422);

    // The same key now carries a write that CAN succeed, and it must.
    irw_create('OT-1', 'key-fail')->assertOk();

    expect(irw_records())->toBe(1);
});

it('refuses a second copy while the first is still running', function () {
    // Claimed atomically, so two concurrent copies of one request cannot both
    // get through. The client's queue stops on the 409 and retries the flush.
    // Scoped explicitly: outside a request there is no tenant context, and
    // TenantCache refuses rather than falling back to a shared key.
    TenantCache::forOwner($this->user->organization_id, $this->user->id)
        ->put('idem:'.hash('sha256', 'r/campo/actions|key-race'), '__in_flight__', 60);

    $response = irw_create('OT-1', 'key-race')->assertStatus(409);

    // Labelled, because to the offline queue a bare 409 is a considered
    // refusal — it would retire a write that in fact just needs asking again.
    expect($response->headers->get('Idempotent-Retry'))->toBe('true')
        ->and(irw_records())->toBe(0);
});

it('guards every write surface the offline queue can replay', function () {
    // The queue holds a write on whatever mount produced it and uploads its
    // attachments to that mount's /uploads. All four are replayable, so all
    // four need the guard — the behaviour above is exercised on /r/…/actions
    // because the other three need object storage or a published portal, but
    // being WIRED is the part that silently goes missing.
    $guarded = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array(IdempotentRuntimeWrite::class, $route->gatherMiddleware(), true))
        ->map(fn ($route) => $route->uri())
        ->sort()
        ->values()
        ->all();

    expect($guarded)->toBe([
        'a/{public_slug}/actions',
        'a/{public_slug}/uploads',
        'r/{app_slug}/actions',
        'r/{app_slug}/uploads',
    ]);
});

it('scopes the key to the tenant', function () {
    // App slugs are unique PER ORGANIZATION, so two tenants really can both be
    // at /r/campo/actions — the path in the key is not what keeps them apart.
    // With one shared Redis keyspace and a client-chosen key, the second
    // tenant's write would be answered with the first tenant's response body
    // and never performed.
    irw_create('OT-1', 'key-abc')->assertOk();

    $stranger = User::factory()->create(['email_verified_at' => now()]);
    // Different tenant — a different organization, or in personal mode a
    // different person. Either way a different RLS scope, and the cache has to
    // draw the same line the database does.
    expect($stranger->id)->not->toBe($this->user->id);

    $strangerApp = App::factory()->create([
        'user_id' => $stranger->id,
        'organization_id' => $stranger->organization_id,
        'slug' => 'campo',
        'name' => 'Ajeno',
    ]);
    $strangerObj = irw_id('obj');
    $strangerRole = irw_id('rol');
    app(AppManifestService::class)->createVersion($strangerApp, [
        'schema_version' => '1.0.0',
        'id' => $strangerApp->id,
        'slug' => 'campo',
        'name' => 'Ajeno',
        'version' => 1,
        'objects' => [[
            'id' => $strangerObj,
            'slug' => 'ordenes',
            'name' => 'Orden',
            'fields' => [['id' => irw_id('fld'), 'slug' => 'folio', 'name' => 'Folio', 'type' => 'string']],
        ]],
        'pages' => [],
        'permissions' => [
            'roles' => [['id' => $strangerRole, 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]],
            'object_policies' => [[
                'object_id' => $strangerObj,
                'role_id' => $strangerRole,
                'actions' => ['create', 'read', 'update', 'delete'],
            ]],
        ],
    ], $stranger);

    $this->actingAs($stranger)
        ->postJson('/r/campo/actions', [
            'actions' => [[
                'type' => 'create_record',
                'object_id' => $strangerObj,
                'values' => ['folio' => 'OT-1'],
            ]],
        ], ['Idempotency-Key' => 'key-abc'])
        ->assertOk();

    expect(Record::query()->where('app_id', $strangerApp->id)->count())->toBe(1);
});
