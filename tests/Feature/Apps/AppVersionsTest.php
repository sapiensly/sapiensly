<?php

use App\Models\App;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use Illuminate\Support\Str;

/**
 * The way back, from the builder.
 *
 * Rolling back has always been possible over MCP and unreachable from the
 * screen where apps are actually changed. A history nobody can reach is a
 * backup nobody has.
 */
function versionedApp(User $owner): App
{
    $app = App::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $owner->organization_id,
        'slug' => 'hist_'.Str::lower(Str::random(6)),
        'name' => 'Historia',
    ]);

    $manifests = app(AppManifestService::class);
    $base = $manifests->initialManifest($app);

    $one = $base;
    $one['objects'] = [[
        'id' => 'obj_primero01',
        'name' => 'Primero',
        'slug' => 'primero',
        'fields' => [['id' => 'fld_nombre001', 'name' => 'Nombre', 'slug' => 'nombre', 'type' => 'string']],
    ]];
    $manifests->createVersion($app, $one, $owner, 'El primero');

    $two = $one;
    $two['objects'][] = [
        'id' => 'obj_segundo01',
        'name' => 'Segundo',
        'slug' => 'segundo',
        'fields' => [['id' => 'fld_nombre002', 'name' => 'Nombre', 'slug' => 'nombre', 'type' => 'string']],
    ];
    $manifests->createVersion($app, $two, $owner, 'El segundo');

    return $app->fresh();
}

it('lists the history newest first, saying which one is live', function () {
    $owner = User::factory()->create();
    $app = versionedApp($owner);

    $response = $this->actingAs($owner)
        ->getJson(route('apps.versions', ['app' => $app->id]))
        ->assertOk();

    $versions = $response->json('versions');

    expect($versions[0]['version_number'])->toBeGreaterThan($versions[1]['version_number'])
        ->and($versions[0]['is_current'])->toBeTrue()
        ->and($versions[0]['change_summary'])->toBe('El segundo')
        // What a version HOLDS, so the list says something beyond a timestamp.
        ->and($versions[0]['objects'])->toBe(2)
        ->and($versions[1]['objects'])->toBe(1);
});

it('restores without deleting anything', function () {
    // Append-only, like the MCP path: the thing you undid stays in the list to
    // redo. A rollback that erased history would make the second mistake
    // unrecoverable.
    $owner = User::factory()->create();
    $app = versionedApp($owner);

    $before = $this->actingAs($owner)
        ->getJson(route('apps.versions', ['app' => $app->id]))
        ->json('versions');

    $target = collect($before)->firstWhere('change_summary', 'El primero');

    $this->actingAs($owner)
        ->postJson(route('apps.versions.restore', ['app' => $app->id, 'version' => $target['id']]))
        ->assertOk()
        ->assertJson(['restored_from' => $target['version_number']]);

    $after = $this->actingAs($owner)
        ->getJson(route('apps.versions', ['app' => $app->id]))
        ->json('versions');

    expect($after)->toHaveCount(count($before) + 1)
        // Back to one object, and the two-object version still on the list.
        ->and($after[0]['objects'])->toBe(1)
        ->and($after[0]['is_current'])->toBeTrue()
        ->and(collect($after)->firstWhere('change_summary', 'El segundo'))->not->toBeNull();
});

it('refuses to restore the version that is already live', function () {
    $owner = User::factory()->create();
    $app = versionedApp($owner);

    $this->actingAs($owner)
        ->postJson(route('apps.versions.restore', [
            'app' => $app->id,
            'version' => $app->current_version_id,
        ]))
        ->assertStatus(422);
});

it('refuses a version id belonging to another app', function () {
    // The id is in the URL. Restoring one app's manifest onto another would be
    // a very quiet way to lose an app.
    $owner = User::factory()->create();
    $mine = versionedApp($owner);
    $theirs = versionedApp($owner);

    $this->actingAs($owner)
        ->postJson(route('apps.versions.restore', [
            'app' => $mine->id,
            'version' => $theirs->current_version_id,
        ]))
        ->assertNotFound();
});

it('is closed to somebody who cannot see the app', function () {
    $owner = User::factory()->create();
    $app = versionedApp($owner);

    $this->actingAs(User::factory()->create())
        ->getJson(route('apps.versions', ['app' => $app->id]))
        ->assertForbidden();
});
