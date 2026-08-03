<?php

use App\Models\App;
use App\Models\AppUserRole;
use App\Models\Record;
use App\Models\RecordEvent;
use App\Models\User;
use App\Services\Apps\AppActivityFeed;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\RecordWriteService;
use Illuminate\Support\Str;

/**
 * Everything that has happened to an app, in one list.
 *
 * Merged at READ time from the sources that already record it. Copying them
 * into one table would double the storage and let the copies disagree — and
 * for one of them it would be actively wrong: activity retention deletes old
 * rows, and app versions ARE the rollback history.
 */
function feedApp(User $owner): array
{
    $app = App::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $owner->organization_id,
        'slug' => 'feed_'.strtolower(Str::random(6)),
        'name' => 'Órdenes',
        'activity_retention_months' => 12,
    ]);

    $manifest = [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => $app->slug,
        'name' => 'Órdenes',
        'version' => 1,
        'settings' => ['default_locale' => 'es-MX'],
        'objects' => [[
            'id' => 'obj_ordenes001',
            'name' => 'Órdenes',
            'slug' => 'ordenes',
            'fields' => [['id' => 'fld_folio00001', 'name' => 'Folio', 'slug' => 'folio', 'type' => 'string']],
        ]],
        'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
    ];

    app(AppManifestService::class)->createVersion($app, $manifest, $owner, 'El primero');

    return [$app->fresh(), $manifest];
}

it('merges every source that already records something', function () {
    $owner = User::factory()->create(['name' => 'Ana']);
    [$app, $manifest] = feedApp($owner);

    // A record event.
    $record = app(RecordWriteService::class)
        ->create($app, $manifest, 'obj_ordenes001', ['folio' => 'A-1'], $owner);

    // A structure change.
    app(AppManifestService::class)->createVersion($app, $manifest, $owner, 'El segundo');

    // An access grant.
    $member = User::factory()->create(['name' => 'Beto']);
    AppUserRole::create([
        'id' => 'aur_'.strtolower((string) Str::ulid()),
        'app_id' => $app->id,
        'assigned_user_id' => $member->id,
        'granted_by_user_id' => $owner->id,
        'role_slug' => 'admin',
    ]);

    $kinds = collect(app(AppActivityFeed::class)->for($app))->pluck('kind')->unique();

    expect($kinds)->toContain('record')
        ->and($kinds)->toContain('structure')
        ->and($kinds)->toContain('access')
        ->and($record->exists)->toBeTrue();
});

it('sends the verb rather than a sentence', function () {
    // A phrase built server-side would be English inside an app written in
    // Spanish — the fault this codebase has now fixed in the filter bar, the
    // runtime's empty states and the chart captions.
    $owner = User::factory()->create();
    [$app, $manifest] = feedApp($owner);

    app(RecordWriteService::class)
        ->create($app, $manifest, 'obj_ordenes001', ['folio' => 'A-1'], $owner);

    $entry = collect(app(AppActivityFeed::class)->for($app))->firstWhere('kind', 'record');

    expect($entry['event'])->toBe('created')
        ->and($entry['summary'])->toBe('');
});

it('reads newest first, across sources', function () {
    $owner = User::factory()->create();
    [$app, $manifest] = feedApp($owner);

    app(RecordWriteService::class)
        ->create($app, $manifest, 'obj_ordenes001', ['folio' => 'A-1'], $owner);

    $entries = app(AppActivityFeed::class)->for($app);
    $dates = array_values(array_filter(array_column($entries, 'at')));
    $sorted = $dates;
    rsort($sorted);

    expect($dates)->toBe($sorted);
});

it('still shows the structure trail when record logging is off', function () {
    // The two lifecycles are separate on purpose: record events expire on the
    // tenant's retention, versions never do — they are the way back.
    $owner = User::factory()->create();
    [$app, $manifest] = feedApp($owner);
    $app->update(['activity_retention_months' => 0]);

    app(RecordWriteService::class)
        ->create($app->fresh(), $manifest, 'obj_ordenes001', ['folio' => 'A-1'], $owner);

    $kinds = collect(app(AppActivityFeed::class)->for($app->fresh()))->pluck('kind')->unique();

    expect($kinds)->toContain('structure')
        ->and($kinds)->not->toContain('record')
        ->and(RecordEvent::where('app_id', $app->id)->count())->toBe(0);
});

it('is closed to somebody who cannot see the app', function () {
    $owner = User::factory()->create();
    [$app] = feedApp($owner);

    $this->actingAs(User::factory()->create())
        ->getJson(route('apps.activity', ['app' => $app->id]))
        ->assertForbidden();
});

it('serves the feed to somebody who can', function () {
    $owner = User::factory()->create();
    [$app] = feedApp($owner);

    $this->actingAs($owner)
        ->getJson(route('apps.activity', ['app' => $app->id]))
        ->assertOk()
        ->assertJsonStructure(['entries' => [['at', 'kind', 'actor', 'summary', 'detail']]]);
});

it('does not choke on a record with no history', function () {
    $owner = User::factory()->create();
    [$app] = feedApp($owner);

    Record::create([
        'app_id' => $app->id,
        'object_definition_id' => 'obj_ordenes001',
        'organization_id' => $app->organization_id,
        'data' => ['folio' => 'huérfano'],
    ]);

    expect(app(AppActivityFeed::class)->for($app))->not->toBeEmpty();
});
