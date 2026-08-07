<?php

use App\Models\App;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Notifications\NotificationSender;
use App\Support\Push\WebPush;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Reaching somebody while the app is closed.
 *
 * The two things worth pinning are about honesty rather than delivery. A
 * subscription is somebody's device address, so it must not be registrable
 * against an app they cannot open — and a send that reached nobody must SAY so,
 * because "the workflow ran" and "the person was told" are different facts and
 * an author told only the first goes looking in the wrong place.
 */
function push_id(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

/** The RFC 8291 subscription — a real key pair, so encryption actually runs. */
const PUSH_KEYS = [
    'p256dh' => 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4',
    'auth' => 'BTBZMqHH6r4Tts7J_aSIgg',
];

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);

    $this->testApp = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'slug' => 'campo',
        'name' => 'Servicio Campo',
    ]);

    $rolId = push_id('rol');

    app(AppManifestService::class)->createVersion($this->testApp, [
        'schema_version' => '1.0.0',
        'id' => $this->testApp->id,
        'slug' => 'campo',
        'name' => 'Servicio Campo',
        'version' => 1,
        'objects' => [[
            'id' => push_id('obj'),
            'slug' => 'ordenes',
            'name' => 'Orden',
            'fields' => [
                ['id' => push_id('fld'), 'slug' => 'folio', 'name' => 'Folio', 'type' => 'string'],
            ],
        ]],
        'pages' => [],
        'permissions' => [
            'roles' => [['id' => $rolId, 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]],
        ],
    ], $this->user);

    $keys = WebPush::generateKeyPair();
    config()->set('push.vapid.public', WebPush::encode($keys['public']));
    config()->set('push.vapid.private', WebPush::encode($keys['private']));
    config()->set('push.vapid.subject', 'mailto:soporte@example.test');
});

function push_subscribe(string $endpoint): TestResponse
{
    return test()->actingAs(test()->user)->postJson('/r/campo/push', [
        'endpoint' => $endpoint,
        'keys' => PUSH_KEYS,
    ]);
}

it('registers a device against the app it was allowed for', function () {
    push_subscribe('https://fcm.googleapis.com/fcm/send/abc')->assertOk();

    expect(PushSubscription::where('app_id', test()->testApp->id)->count())->toBe(1);
});

it('keeps one row per device however often the browser re-registers', function () {
    // A browser re-registers on every visit and hands back the same endpoint
    // with a fresh key pair after a worker update. Without the endpoint being
    // the identity, a phone accumulates a row per visit and every notification
    // is delivered to it a dozen times.
    push_subscribe('https://fcm.googleapis.com/fcm/send/abc')->assertOk();
    push_subscribe('https://fcm.googleapis.com/fcm/send/abc')->assertOk();

    expect(PushSubscription::where('app_id', test()->testApp->id)->count())->toBe(1);
});

it('refuses a device for an app the person cannot open', function () {
    $stranger = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($stranger)->postJson('/r/campo/push', [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc',
        'keys' => PUSH_KEYS,
    ])->assertNotFound();

    expect(PushSubscription::count())->toBe(0);
});

it('forgets a device that asked to stop', function () {
    push_subscribe('https://fcm.googleapis.com/fcm/send/abc')->assertOk();

    $this->actingAs($this->user)
        ->deleteJson('/r/campo/push', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc'])
        ->assertOk();

    expect(PushSubscription::count())->toBe(0);
});

it('sends the message encrypted, signed, and to the right endpoint', function () {
    Http::fake(['*' => Http::response('', 201)]);
    push_subscribe('https://fcm.googleapis.com/fcm/send/abc')->assertOk();

    $result = app(NotificationSender::class)->send(
        app: $this->testApp,
        channel: 'push',
        references: ['user:'.$this->user->id],
        title: 'Orden OS-4471',
        body: 'Te asignaron una visita.',
        link: '/r/campo/ordenes',
    );

    expect($result['sent'])->toBe(1)
        ->and($result['failed'])->toBe([]);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://fcm.googleapis.com/fcm/send/abc'
            && $request->header('Content-Encoding')[0] === 'aes128gcm'
            && str_starts_with($request->header('Authorization')[0], 'vapid t=')
            // Nothing legible on the wire: a push service routes these and has
            // no business reading a customer's name or a visit's address.
            && ! str_contains($request->body(), 'OS-4471');
    });
});

it('says nobody has a device rather than reporting a send', function () {
    Http::fake();

    $result = app(NotificationSender::class)->send(
        app: $this->testApp,
        channel: 'push',
        references: ['user:'.$this->user->id],
        title: 'Orden OS-4471',
        body: 'Te asignaron una visita.',
    );

    expect($result['sent'])->toBe(0)
        ->and($result['failed'][0])->toContain('no device has allowed notifications');

    Http::assertNothingSent();
});

it('says push is not configured instead of failing per recipient', function () {
    config()->set('push.vapid.public', null);
    config()->set('push.vapid.private', null);

    $result = app(NotificationSender::class)->send(
        app: $this->testApp,
        channel: 'push',
        references: ['user:'.$this->user->id],
        title: 'Hola',
        body: 'Hola',
    );

    expect($result['sent'])->toBe(0)
        ->and($result['failed'])->toBe(['push notifications are not configured on this installation']);
});

it('drops a device the push service says is gone', function () {
    // A browser that was uninstalled, cleared or revoked keeps its endpoint
    // alive as a 410 for ever. Keeping the row means a request per
    // notification per dead device, permanently.
    Http::fake(['*' => Http::response('', 410)]);
    push_subscribe('https://fcm.googleapis.com/fcm/send/abc')->assertOk();

    app(NotificationSender::class)->send(
        app: $this->testApp,
        channel: 'push',
        references: ['user:'.$this->user->id],
        title: 'Hola',
        body: 'Hola',
    );

    expect(PushSubscription::count())->toBe(0);
});

it('never reaches a person during a verification run', function () {
    Http::fake();
    push_subscribe('https://fcm.googleapis.com/fcm/send/abc')->assertOk();

    $result = app(NotificationSender::class)->send(
        app: $this->testApp,
        channel: 'push',
        references: ['user:'.$this->user->id],
        title: 'Hola',
        body: 'Hola',
        dryRun: true,
    );

    expect($result['simulated'])->toBeTrue();
    Http::assertNothingSent();
});
