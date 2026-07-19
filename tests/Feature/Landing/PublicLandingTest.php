<?php

use App\Models\App;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use Illuminate\Support\Str;

/**
 * Build a published landing app (kind=landing via settings.surface) with the
 * given page blocks, ready to hit /l/{public_slug} as a guest.
 */
function publishedLanding(array $blocks, array $settingsExtra = [], array $objects = []): App
{
    $user = User::factory()->create();
    $app = App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->organization_id,
        'slug' => 'lp_'.strtolower(Str::random(6)),
        'name' => 'Lanzamiento',
        'public_slug' => 'pub_'.strtolower(Str::random(6)),
        'published_at' => now(),
    ]);

    $manifests = app(AppManifestService::class);
    $manifest = $manifests->initialManifest($app);
    $manifest['settings'] = array_merge($manifest['settings'] ?? [], ['surface' => 'landing'], $settingsExtra);
    $manifest['objects'] = $objects;
    $manifest['pages'] = [[
        'id' => 'pag_publichome', 'slug' => 'home', 'name' => 'Home', 'path' => '/', 'blocks' => $blocks,
    ]];
    $manifests->createVersion($app, $manifest, $user, 'landing');

    return $app->refresh();
}

it('serves a published landing to a guest, chrome-less and eager', function () {
    $app = publishedLanding([
        ['id' => 'htm_pubhero01', 'type' => 'html', 'content' => '<section class="hero"><h1>Hola mundo</h1></section>'],
    ]);

    $response = $this->get("/l/{$app->public_slug}");

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('runtime/Page')
            ->where('app.kind', 'landing')
            // The public identity is the public slug; the tenant slug stays server-side.
            ->where('app.slug', $app->public_slug)
            ->where('page.blocks.0.type', 'html')
            // Eager, not deferred: complete on first response for SSR/SEO.
            ->where('blockData', [])
            ->where('seo.title', 'Lanzamiento')
        );
});

it('404s an unpublished landing', function () {
    $app = publishedLanding([
        ['id' => 'htm_pubhero01', 'type' => 'html', 'content' => '<section><h1>x</h1></section>'],
    ]);
    $slug = $app->public_slug;
    $app->forceFill(['published_at' => null])->save();

    $this->get("/l/{$slug}")->assertNotFound();
});

it('404s a published app that is not a landing', function () {
    $app = publishedLanding([
        ['id' => 'htm_pubhero01', 'type' => 'html', 'content' => '<section><h1>x</h1></section>'],
    ]);
    // Simulate the kind drifting away from landing after publish.
    $app->forceFill(['kind' => 'app'])->save();

    $this->get("/l/{$app->public_slug}")->assertNotFound();
});

it('404s an unknown public slug', function () {
    $this->get('/l/no_such_landing')->assertNotFound();
});

it('strips data-backed and visibility-ruled blocks from the public page', function () {
    $app = publishedLanding(
        [
            ['id' => 'htm_pubhero01', 'type' => 'html', 'content' => '<section><h1>Público</h1></section>'],
            // Data-backed: must never render to a guest.
            ['id' => 'tb_secret001', 'type' => 'table',
                'data_source' => ['object_id' => 'obj_leadsdata1'],
                'columns' => [['id' => 'col_leadname1', 'field_id' => 'fld_leadname01']]],
            // Visibility-ruled: fail closed for anonymous visitors.
            ['id' => 'txt_roleonly1', 'type' => 'text', 'content' => 'solo admins',
                'visibility' => ['expression' => '{{params.secret}}']],
        ],
        objects: [[
            'id' => 'obj_leadsdata1', 'slug' => 'leads', 'name' => 'Leads',
            'fields' => [['id' => 'fld_leadname01', 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string']],
        ]],
    );

    $response = $this->get("/l/{$app->public_slug}");

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->has('page.blocks', 1)
        ->where('page.blocks.0.id', 'htm_pubhero01')
    );
    $response->assertDontSee('solo admins');
});

it('uses authored settings.seo for the head metadata', function () {
    $app = publishedLanding(
        [['id' => 'htm_pubhero01', 'type' => 'html', 'content' => '<section><h1>x</h1></section>']],
        ['seo' => [
            'title' => 'Lanza tu SaaS en minutos',
            'description' => 'La landing que se atiende sola.',
            'og_image' => 'https://cdn.example.com/og.png',
        ]],
    );

    $this->get("/l/{$app->public_slug}")
        ->assertInertia(fn ($page) => $page
            ->where('seo.title', 'Lanza tu SaaS en minutos')
            ->where('seo.description', 'La landing que se atiende sola.')
            ->where('seo.og_image', 'https://cdn.example.com/og.png')
        );
});

it('does not expose the tenant objects or an agent to guests', function () {
    $app = publishedLanding([
        ['id' => 'htm_pubhero01', 'type' => 'html', 'content' => '<section><h1>x</h1></section>'],
    ]);

    $this->get("/l/{$app->public_slug}")
        ->assertInertia(fn ($page) => $page
            ->where('manifest.objects', [])
            ->where('manifest.agent', null)
        );
});
