<?php

use App\Models\App;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\AppScaffolder;

/**
 * The install manifest is PER APP.
 *
 * A technician installs «Servicio Campo» and gets that app on the home screen —
 * its name, its icon, opening at its own url. One shared platform icon for
 * every app an organization builds makes the install worthless the moment they
 * build a second one.
 */
beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->testApp = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'slug' => 'servicio_campo',
        'name' => 'Servicio Campo Industrial',
        'color' => '#ff5500',
    ]);

    $manifests = app(AppManifestService::class);
    $manifest = app(AppScaffolder::class)->assemble($manifests->initialManifest($this->testApp), [
        'objects' => [['name' => 'Ordenes', 'slug' => 'ordenes', 'fields' => [
            ['name' => 'Folio', 'slug' => 'folio', 'type' => 'string', 'options' => null],
        ]]],
    ]);
    $manifests->createVersion($this->testApp, $manifest, $this->user, 'seed');
});

it('describes the app itself, not the platform', function () {
    $this->actingAs($this->user)
        ->get('/r/servicio_campo/manifest.webmanifest')
        ->assertOk()
        ->assertJsonPath('name', 'Servicio Campo Industrial')
        // Home screens truncate hard, so the short name is what actually shows.
        ->assertJsonPath('short_name', 'Servicio Cam')
        ->assertJsonPath('theme_color', '#ff5500')
        ->assertJsonPath('display', 'standalone');
});

it('scopes the installed window to this app and nothing around it', function () {
    // An installed app that captured /apps or /admin would put the whole
    // platform inside a window built for one screen.
    $this->actingAs($this->user)
        ->get('/r/servicio_campo/manifest.webmanifest')
        ->assertOk()
        ->assertJsonPath('scope', '/r/servicio_campo')
        ->assertJsonPath('start_url', '/r/servicio_campo');
});

it('always offers an icon, even with no Brandbook', function () {
    $icons = $this->actingAs($this->user)
        ->get('/r/servicio_campo/manifest.webmanifest')
        ->assertOk()
        ->json('icons');

    expect($icons)->toHaveCount(1)
        ->and($icons[0]['src'])->not->toBeEmpty()
        ->and($icons[0]['type'])->toBe('image/svg+xml');
});

it('is not readable by somebody who cannot open the app', function () {
    // The manifest carries the app's name and description. It answers to the
    // same session the runtime does.
    $stranger = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($stranger)
        ->get('/r/servicio_campo/manifest.webmanifest')
        ->assertNotFound();
});

it('404s for an app that does not exist', function () {
    $this->actingAs($this->user)
        ->get('/r/no_existe/manifest.webmanifest')
        ->assertNotFound();
});
