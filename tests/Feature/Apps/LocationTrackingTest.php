<?php

use App\Models\App;
use App\Models\LocationPing;
use App\Models\Record;
use App\Models\TrackingSession;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Following somebody, and noticing that they arrived.
 *
 * The arrival is the reason this exists: a trail is a picture somebody looks at
 * afterwards, but `location.arrived` closes the loop — the visit stamps itself
 * and the customer is told. So the tests here are about the two ways that can
 * be wrong. It must fire ONCE for one visit (a phone at the edge of a fence
 * wanders, and a workflow that runs forty times sends forty messages), and it
 * must not fire at all for an app whose owner never turned tracking on.
 */
function trk_id(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

/** The Zócalo, and a point about 3 km away. */
const SITE = ['lat' => 19.4326, 'lng' => -99.1332];

const FAR = ['lat' => 19.427, 'lng' => -99.1677];

const NEAR = ['lat' => 19.4327, 'lng' => -99.1333];

function trk_build(User $owner, bool $tracking = true): App
{
    $app = App::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $owner->organization_id,
        'slug' => 'campo',
        'name' => 'Servicio Campo',
    ]);

    $objId = trk_id('obj');
    $rolId = trk_id('rol');
    $geoId = trk_id('fld');

    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => 'campo',
        'name' => 'Servicio Campo',
        'version' => 1,
        'settings' => $tracking
            ? ['tracking' => ['enabled' => true, 'geofence_radius_m' => 150]]
            : ['default_locale' => 'es'],
        'objects' => [[
            'id' => $objId,
            'slug' => 'visitas',
            'name' => 'Visita',
            'fields' => [
                ['id' => trk_id('fld'), 'slug' => 'folio', 'name' => 'Folio', 'type' => 'string'],
                ['id' => $geoId, 'slug' => 'sitio', 'name' => 'Sitio', 'type' => 'geo'],
            ],
        ]],
        'pages' => [],
        'workflows' => [[
            'id' => trk_id('wfl'),
            'slug' => 'avisar_llegada',
            'name' => 'Avisar llegada',
            'trigger' => ['type' => 'location.arrived', 'object_id' => $objId],
            'steps' => [[
                'id' => trk_id('stp'),
                'type' => 'notify.send',
                'channel' => 'email',
                'to' => ['cliente@example.test'],
                'subject' => 'El técnico llegó',
                'body' => 'Está a {{trigger.location.distance_m}} metros del sitio.',
            ]],
        ]],
        'permissions' => [
            'roles' => [['id' => $rolId, 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]],
            'object_policies' => [[
                'object_id' => $objId, 'role_id' => $rolId,
                'actions' => ['create', 'read', 'update', 'delete'],
            ]],
        ],
    ], $owner);

    test()->objId = $objId;

    return $app->fresh();
}

function trk_visit(App $app): Record
{
    return Record::create([
        'app_id' => $app->id,
        'object_definition_id' => test()->objId,
        'organization_id' => $app->organization_id,
        'data' => ['folio' => 'V-1', 'sitio' => SITE],
    ]);
}

/** @param list<array<string, mixed>> $pings */
function trk_send(string $sessionId, array $pings): TestResponse
{
    return test()->actingAs(test()->user)->postJson('/r/campo/tracking/pings', [
        'session_id' => $sessionId,
        'pings' => $pings,
    ]);
}

beforeEach(function () {
    Mail::fake();
    $this->user = User::factory()->create(['email_verified_at' => now()]);
});

it('records a trail only for an app whose owner turned it on', function () {
    trk_build($this->user, tracking: false);

    $this->actingAs($this->user)
        ->postJson('/r/campo/tracking/start', [])
        ->assertForbidden();

    expect(TrackingSession::count())->toBe(0);
});

it('starts a session and stores what the phone reported', function () {
    trk_build($this->user);

    $sessionId = $this->actingAs($this->user)
        ->postJson('/r/campo/tracking/start', [])
        ->assertOk()
        ->json('session_id');

    trk_send($sessionId, [
        ['lat' => FAR['lat'], 'lng' => FAR['lng'], 'accuracy' => 12],
        ['lat' => NEAR['lat'], 'lng' => NEAR['lng'], 'accuracy' => 12, 'gap' => true],
    ])->assertOk();

    expect(LocationPing::count())->toBe(2)
        ->and(LocationPing::where('gap', true)->count())->toBe(1);
});

it('fires the arrival workflow once, not once per reading at the fence', function () {
    // A phone sitting still at the edge of a fence wanders by tens of metres,
    // because that is what a fix does. Without the hysteresis this sends the
    // customer forty messages for one visit.
    $app = trk_build($this->user);
    $visit = trk_visit($app);

    $sessionId = $this->actingAs($this->user)
        ->postJson('/r/campo/tracking/start', ['record_id' => $visit->id])
        ->assertOk()
        ->assertJson(['has_geofence' => true])
        ->json('session_id');

    // Approaching, then arriving, then wobbling around the boundary.
    $result = trk_send($sessionId, [
        ['lat' => FAR['lat'], 'lng' => FAR['lng'], 'accuracy' => 12],
        ['lat' => NEAR['lat'], 'lng' => NEAR['lng'], 'accuracy' => 12],
        // ~170 m out: past the fence, inside the exit ring. This is the
        // reading the hysteresis exists for.
        ['lat' => 19.4337, 'lng' => -99.1343, 'accuracy' => 12],
        ['lat' => NEAR['lat'], 'lng' => NEAR['lng'], 'accuracy' => 12],
    ])->assertOk();

    expect($result->json('events'))->toBe(['location.arrived'])
        ->and($result->json('inside'))->toBeTrue();
});

it('does not call it an arrival when the trail started at the site', function () {
    // Somebody who opens the app already standing at the door has not arrived
    // while it was watching, and firing here is how a workflow sends "vamos en
    // camino" from the customer's own doorstep.
    $app = trk_build($this->user);
    $visit = trk_visit($app);

    $sessionId = $this->actingAs($this->user)
        ->postJson('/r/campo/tracking/start', ['record_id' => $visit->id])
        ->json('session_id');

    $result = trk_send($sessionId, [
        ['lat' => NEAR['lat'], 'lng' => NEAR['lng'], 'accuracy' => 12],
    ])->assertOk();

    expect($result->json('events'))->toBe([])
        ->and($result->json('inside'))->toBeTrue();
});

it('notices the way out too', function () {
    $app = trk_build($this->user);
    $visit = trk_visit($app);

    $sessionId = $this->actingAs($this->user)
        ->postJson('/r/campo/tracking/start', ['record_id' => $visit->id])
        ->json('session_id');

    $result = trk_send($sessionId, [
        ['lat' => FAR['lat'], 'lng' => FAR['lng'], 'accuracy' => 12],
        ['lat' => NEAR['lat'], 'lng' => NEAR['lng'], 'accuracy' => 12],
        ['lat' => FAR['lat'], 'lng' => FAR['lng'], 'accuracy' => 12],
    ])->assertOk();

    expect($result->json('events'))->toBe(['location.arrived', 'location.departed']);
});

it('decides nothing from a reading too vague to decide with', function () {
    // "Within three kilometres" cannot say whether somebody is inside a 150 m
    // fence. Answering anyway stamps a visit from the depot.
    $app = trk_build($this->user);
    $visit = trk_visit($app);

    $sessionId = $this->actingAs($this->user)
        ->postJson('/r/campo/tracking/start', ['record_id' => $visit->id])
        ->json('session_id');

    $result = trk_send($sessionId, [
        ['lat' => NEAR['lat'], 'lng' => NEAR['lng'], 'accuracy' => 3000],
    ])->assertOk();

    expect($result->json('events'))->toBe([])
        ->and($result->json('inside'))->toBeNull();
});

it('takes nothing more once the person has stopped', function () {
    // Pressing stop ends the thing they were asked to consent to. A client
    // that keeps posting must not be able to override that.
    trk_build($this->user);

    $sessionId = $this->actingAs($this->user)
        ->postJson('/r/campo/tracking/start', [])
        ->json('session_id');

    $this->actingAs($this->user)
        ->postJson('/r/campo/tracking/stop', ['session_id' => $sessionId])
        ->assertOk();

    trk_send($sessionId, [['lat' => NEAR['lat'], 'lng' => NEAR['lng']]])
        ->assertStatus(409);

    expect(LocationPing::count())->toBe(0);
});

it('never runs two trails for one person', function () {
    // Two trails is a question nobody can answer: which of these is where they
    // were?
    trk_build($this->user);

    $first = $this->actingAs($this->user)->postJson('/r/campo/tracking/start', [])->json('session_id');
    $this->actingAs($this->user)->postJson('/r/campo/tracking/start', [])->assertOk();

    expect(TrackingSession::find($first)->ended_at)->not->toBeNull()
        ->and(TrackingSession::whereNull('ended_at')->count())->toBe(1);
});

it('refuses to feed somebody else’s trail', function () {
    trk_build($this->user);

    $sessionId = $this->actingAs($this->user)
        ->postJson('/r/campo/tracking/start', [])
        ->json('session_id');

    $other = User::factory()->create([
        'email_verified_at' => now(),
        'organization_id' => $this->user->organization_id,
    ]);

    $this->actingAs($other)
        ->postJson('/r/campo/tracking/pings', [
            'session_id' => $sessionId,
            'pings' => [['lat' => NEAR['lat'], 'lng' => NEAR['lng']]],
        ])
        ->assertNotFound();

    expect(LocationPing::count())->toBe(0);
});

it('ignores a point in the middle of the Atlantic', function () {
    // Zero, zero is a real place and also what a half-filled payload looks
    // like. One of those is far more likely than the other.
    trk_build($this->user);

    $sessionId = $this->actingAs($this->user)
        ->postJson('/r/campo/tracking/start', [])
        ->json('session_id');

    trk_send($sessionId, [['lat' => 0, 'lng' => 0]])->assertOk();

    expect(LocationPing::count())->toBe(0);
});

it('deletes a trail past the app’s retention', function () {
    $app = trk_build($this->user);

    $sessionId = $this->actingAs($this->user)
        ->postJson('/r/campo/tracking/start', [])
        ->json('session_id');

    trk_send($sessionId, [['lat' => NEAR['lat'], 'lng' => NEAR['lng']]])->assertOk();

    // A phone that died mid-visit and was never heard from again: the points
    // are past the retention AND the session was never closed by anybody.
    LocationPing::query()->update(['recorded_at' => now()->subDays(45)]);
    TrackingSession::query()->update([
        'started_at' => now()->subDays(45),
        'last_ping_at' => now()->subDays(45),
    ]);

    $this->artisan('tracking:prune')->assertSuccessful();

    expect(LocationPing::count())->toBe(0)
        // …and the session a phone abandoned is closed, so "who is being
        // followed right now" stays answerable.
        ->and(TrackingSession::find($sessionId)->ended_at)->not->toBeNull();
});
