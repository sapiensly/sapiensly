<?php

use App\Events\Apps\RecordChanged;
use App\Models\Record;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\RecordWriteService;
use App\Support\Broadcasting\SafeBroadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * A dead broadcaster must cost the work it decorates ONE attempt, not one each.
 *
 * Not a tidiness concern. A refused connection to Reverb does not fail fast —
 * it takes about thirty seconds — and the two callers here are the hottest
 * paths in the product: every builder tool call, and every single record write.
 *
 * Measured before this existed: a builder turn burned its whole 300s budget on
 * a dozen announcements and died telling the user their request was too big,
 * and seeding three demo records took 90.1 seconds. Three writes. Thirty
 * seconds each. No work in between.
 */
beforeEach(function () {
    SafeBroadcast::resume();
});

it('stops broadcasting after one failure', function () {
    $attempts = 0;

    // A bulk action's worth of writes against a broadcaster that is down.
    foreach (range(1, 50) as $ignored) {
        SafeBroadcast::dispatch(function () use (&$attempts): void {
            $attempts++;
            throw new RuntimeException('Connection refused for URI https://sapiensly.test:8080/apps/1/events');
        });
    }

    // One, not fifty. At ~30s apiece that is thirty seconds against twenty-five
    // minutes for a bulk action that had already written every row.
    expect($attempts)->toBe(1);
});

it('never lets a broadcast failure escape into the work', function () {
    // The record is already written and the builder message already persisted.
    // Losing either over a cosmetic live update never made sense.
    SafeBroadcast::dispatch(fn () => throw new RuntimeException('boom'));
})->throwsNoExceptions();

it('keeps broadcasting while the broadcaster is healthy', function () {
    $sent = 0;

    foreach (range(1, 5) as $ignored) {
        SafeBroadcast::dispatch(function () use (&$sent): void {
            $sent++;
        });
    }

    expect($sent)->toBe(5);
});

it('picks broadcasting back up once the cool-down passes', function () {
    // Silence has to expire. A broadcaster that recovers must be noticed while
    // somebody is still looking at the page.
    SafeBroadcast::dispatch(fn () => throw new RuntimeException('down'));

    $sent = 0;
    SafeBroadcast::dispatch(function () use (&$sent): void {
        $sent++;
    });
    expect($sent)->toBe(0);

    $this->travel(61)->seconds();

    SafeBroadcast::dispatch(function () use (&$sent): void {
        $sent++;
    });
    expect($sent)->toBe(1);
});

it('silences every caller, not just the one that failed', function () {
    // The builder and the record write path are separate classes hitting the
    // same Reverb. One discovering it is down must spare the other.
    SafeBroadcast::dispatch(fn () => throw new RuntimeException('down'));

    expect(Cache::get('broadcaster:unavailable'))->toBeTrue();

    $sent = 0;
    SafeBroadcast::dispatch(function () use (&$sent): void {
        $sent++;
    });

    expect($sent)->toBe(0);
});

it('costs a bulk record write one announcement, not one per row', function () {
    // The question that found this: seed_records took 90.1s for THREE records.
    // Not the model, not generation — three announcements at thirty seconds.
    // RecordWriteService is the path every create/update/delete takes, so the
    // same arithmetic applied to every app in the product.
    $owner = User::factory()->create();
    $app = App\Models\App::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $owner->organization_id,
        'slug' => 'bcast_'.Str::lower(Str::random(6)),
    ]);

    $manifest = [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => $app->slug,
        'name' => 'Cosas',
        'version' => 1,
        'objects' => [[
            'id' => 'obj_cosa00000001',
            'slug' => 'cosas',
            'name' => 'Cosas',
            'fields' => [['id' => 'fld_nombre000001', 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string', 'required' => true]],
        ]],
        'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
    ];
    app(AppManifestService::class)->createVersion($app, $manifest, $owner);

    $announcements = 0;
    Event::listen(
        RecordChanged::class,
        function () use (&$announcements): void {
            $announcements++;
            throw new RuntimeException('Connection refused for URI https://sapiensly.test:8080/apps/1/events');
        },
    );

    $writes = app(RecordWriteService::class);

    foreach (range(1, 10) as $i) {
        $writes->create($app->fresh(), $manifest, 'obj_cosa00000001', ['nombre' => "Cosa {$i}"], $owner);
    }

    // Every row still written — the announcement is cosmetic and must never
    // decide whether the data lands.
    expect(Record::where('object_definition_id', 'obj_cosa00000001')->count())->toBe(10)
        ->and($announcements)->toBe(1);
});
