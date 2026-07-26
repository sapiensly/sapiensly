<?php

use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Build\RenderLandingTool;
use App\Models\App;
use App\Models\User;
use App\Services\Landing\HeadlessLandingShot;
use App\Services\Manifest\AppManifestService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Ai\Files\StoredImage;

/**
 * A landing app (kind=landing via settings.surface) owned by $user — NOT
 * published, to prove the render route serves drafts too.
 */
function renderableLanding(User $user, string $slug = 'lp_render'): App
{
    $app = App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->organization_id,
        'slug' => $slug.'_'.strtolower(Str::random(5)),
        'name' => 'Render LP',
    ]);
    $manifests = app(AppManifestService::class);
    $manifest = $manifests->initialManifest($app);
    $manifest['settings'] = array_merge($manifest['settings'] ?? [], ['surface' => 'landing']);
    $manifest['pages'] = [[
        'id' => 'pag_renderhome', 'slug' => 'home', 'name' => 'Home', 'path' => '/',
        'blocks' => [['id' => 'htm_renderhero', 'type' => 'html', 'content' => '<section class="hero"><h1>Render me</h1></section>']],
    ]];
    $manifests->createVersion($app, $manifest, $user, 'landing');

    return $app->refresh();
}

it('renders an owner landing over the signed route — even unpublished', function () {
    $user = User::factory()->create();
    $app = renderableLanding($user);
    expect($app->published_at)->toBeNull();

    $url = URL::temporarySignedRoute('landing.render', now()->addMinutes(5), [
        'app' => $app->id, 'org' => $user->organization_id, 'uid' => $user->id,
    ]);

    $this->get($url)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('runtime/Page')
            ->where('app.kind', 'landing')
            ->where('page.blocks.0.type', 'html')
            // Preview shot, not the live page: lead forms render inert.
            ->where('publicSurface', false)
        );
});

it('403s the render route without a valid signature', function () {
    $user = User::factory()->create();
    $app = renderableLanding($user);

    // A plain (unsigned) URL to the signed route.
    $this->get("/apps/{$app->id}/landing-render?org={$user->organization_id}&uid={$user->id}")
        ->assertForbidden();
});

it('404s the render route when the signed owner does not match', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    $app = renderableLanding($user);

    // Validly signed, but uid points at someone who cannot see the app.
    $url = URL::temporarySignedRoute('landing.render', now()->addMinutes(5), [
        'app' => $app->id, 'org' => $stranger->organization_id, 'uid' => $stranger->id,
    ]);

    $this->get($url)->assertNotFound();
});

it('render_landing rejects a non-landing app', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    App::factory()->create([
        'user_id' => $user->id, 'organization_id' => $user->organization_id, 'slug' => 'plain_app',
    ]);

    SapiensServer::actingAs($user)
        ->tool(RenderLandingTool::class, ['app_slug' => 'plain_app'])
        ->assertSee('is not a landing');
});

it('render_landing returns the screenshot image for a landing', function () {
    Storage::fake('local');
    // A real (tiny) JPEG — the MCP image content rejects non-image bytes.
    $img = imagecreatetruecolor(4, 4);
    ob_start();
    imagejpeg($img);
    $jpeg = (string) ob_get_clean();
    imagedestroy($img);
    Storage::disk('local')->put('tmp/render-shots/fixture.jpg', $jpeg);

    $user = User::factory()->create(['email_verified_at' => now()]);
    $app = renderableLanding($user, 'shot');

    $fake = Mockery::mock(HeadlessLandingShot::class);
    $fake->shouldReceive('capture')->once()->andReturn(new StoredImage('tmp/render-shots/fixture.jpg', 'local'));
    $fake->shouldReceive('cleanup')->once();
    app()->instance(HeadlessLandingShot::class, $fake);

    // The JSON metadata half of the response confirms the tool ran and shot it.
    SapiensServer::actingAs($user)
        ->tool(RenderLandingTool::class, ['app_slug' => $app->slug])
        ->assertSee('Full-page screenshot');
});

it('render_landing reports honestly when no headless browser answers', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $app = renderableLanding($user, 'noshot');

    $fake = Mockery::mock(HeadlessLandingShot::class);
    $fake->shouldReceive('capture')->once()->andReturnNull();
    app()->instance(HeadlessLandingShot::class, $fake);

    SapiensServer::actingAs($user)
        ->tool(RenderLandingTool::class, ['app_slug' => $app->slug])
        ->assertSee('no headless browser answered');
});
