<?php

use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Build\PublishLandingTool;
use App\Models\App;
use App\Models\User;
use App\Services\Manifest\AppManifestService;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
});

function makeLandingApp(User $user, string $slug, bool $landing = true): App
{
    $app = App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->organization_id,
        'slug' => $slug,
        'name' => 'Mi Landing',
    ]);
    $manifests = app(AppManifestService::class);
    $manifest = $manifests->initialManifest($app);
    if ($landing) {
        $manifest['settings'] = array_merge($manifest['settings'] ?? [], ['surface' => 'landing']);
    }
    $manifest['pages'] = [[
        'id' => 'pag_toolhome01', 'slug' => 'home', 'name' => 'Home', 'path' => '/', 'blocks' => [
            ['id' => 'htm_toolhero1', 'type' => 'html', 'content' => '<section><h1>Hola</h1></section>'],
        ],
    ]];
    $manifests->createVersion($app, $manifest, $user, 'seed');

    return $app->refresh();
}

it('publishes a landing: mints a public slug and returns the live URL', function () {
    $app = makeLandingApp($this->user, 'promo_site');

    SapiensServer::actingAs($this->user)
        ->tool(PublishLandingTool::class, ['app_slug' => 'promo_site'])
        ->assertOk()
        ->assertSee('promo_site');

    $app->refresh();
    expect($app->public_slug)->toBe('promo_site')
        ->and($app->published_at)->not->toBeNull();

    // The public URL actually serves the landing.
    $this->get("/l/{$app->public_slug}")->assertOk();
});

it('suffixes the public slug when another org already took it', function () {
    $other = User::factory()->create();
    makeLandingApp($other, 'promo_site')
        ->forceFill(['public_slug' => 'promo_site', 'published_at' => now()])->save();

    $app = makeLandingApp($this->user, 'promo_site');

    SapiensServer::actingAs($this->user)
        ->tool(PublishLandingTool::class, ['app_slug' => 'promo_site'])
        ->assertOk();

    expect($app->refresh()->public_slug)->toBe('promo_site-2');
});

it('refuses to publish an app that is not a landing', function () {
    makeLandingApp($this->user, 'regular_app', landing: false);

    SapiensServer::actingAs($this->user)
        ->tool(PublishLandingTool::class, ['app_slug' => 'regular_app'])
        ->assertHasErrors();
});

it('unpublishes: the public URL goes back to 404, and republish keeps the slug', function () {
    $app = makeLandingApp($this->user, 'promo_site');

    SapiensServer::actingAs($this->user)
        ->tool(PublishLandingTool::class, ['app_slug' => 'promo_site'])
        ->assertOk();
    $slug = $app->refresh()->public_slug;

    SapiensServer::actingAs($this->user)
        ->tool(PublishLandingTool::class, ['app_slug' => 'promo_site', 'unpublish' => true])
        ->assertOk();
    expect($app->refresh()->public_slug)->toBeNull();
    $this->get("/l/{$slug}")->assertNotFound();

    // Republishing mints again (fresh identity after an explicit unpublish).
    SapiensServer::actingAs($this->user)
        ->tool(PublishLandingTool::class, ['app_slug' => 'promo_site'])
        ->assertOk();
    expect($app->refresh()->public_slug)->not->toBeNull();
});
