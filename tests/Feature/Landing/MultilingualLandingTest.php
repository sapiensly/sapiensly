<?php

use App\Models\App;
use App\Models\Organization;
use App\Models\User;
use App\Services\Landing\LandingPublisher;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\InvalidManifestException;
use App\Support\Landing\LandingLanguages;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * One landing, several languages.
 *
 * The page ships no JavaScript — the sanitiser strips it — so it cannot read
 * navigator.language and cannot switch itself. The language is decided on the
 * server, from Accept-Language, one moment before the HTML is sent. Everything
 * here guards that decision and the two things that make it usable rather than
 * merely clever: a shareable URL per language, and never overriding a visitor
 * who already chose.
 */
beforeEach(function () {
    $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(6))]);
    $this->user = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $this->org->id]);
    $this->manifests = app(AppManifestService::class);
    $this->app_ = App::factory()->create(['user_id' => $this->user->id, 'organization_id' => $this->org->id]);
});

/**
 * @param  array<string, mixed>  $settings
 * @param  array<string, string>  $variants
 * @return array<string, mixed>
 */
function bilingualManifest(App $app, array $settings = [], array $variants = ['es' => '<section id="top"><h1>Hola</h1></section>']): array
{
    $block = ['id' => 'blk_hero', 'type' => 'html', 'content' => '<section id="top"><h1>Hello</h1></section>'];
    if ($variants !== []) {
        $block['content_i18n'] = $variants;
    }

    return [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => 'mi_landing',
        'name' => $app->name,
        'version' => 1,
        'objects' => [],
        'pages' => [[
            'id' => 'pg_home', 'slug' => 'home', 'path' => '/', 'name' => 'Home',
            'blocks' => [$block],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_admin', 'slug' => 'admin', 'name' => 'Admin']]],
        'settings' => array_merge(['surface' => 'landing', 'languages' => ['en', 'es']], $settings),
    ];
}

function publishBilingual(mixed $ctx, array $settings = [], array $variants = ['es' => '<section id="top"><h1>Hola</h1></section>']): string
{
    $ctx->manifests->createVersion($ctx->app_, bilingualManifest($ctx->app_, $settings, $variants), $ctx->user);

    return app(LandingPublisher::class)->publish($ctx->app_->fresh())['public_slug'];
}

it('serves the language the browser asks for', function () {
    $slug = publishBilingual($this);

    $this->withHeaders(['Accept-Language' => 'es-MX,es;q=0.9,en;q=0.6'])
        ->get("/l/{$slug}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('language', 'es')
            ->where('page.blocks.0.content', '<section id="top"><h1>Hola</h1></section>'));

    $this->withHeaders(['Accept-Language' => 'en-GB,en;q=0.9'])
        ->get("/l/{$slug}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('language', 'en')
            ->where('page.blocks.0.content', '<section id="top"><h1>Hello</h1></section>'));
});

it('honours q-weights rather than header order', function () {
    $slug = publishBilingual($this);

    $this->withHeaders(['Accept-Language' => 'en;q=0.3,es;q=0.9'])
        ->get("/l/{$slug}")
        ->assertInertia(fn ($page) => $page->where('language', 'es'));
});

it('falls back to the default language when the browser wants neither', function () {
    $slug = publishBilingual($this);

    $this->withHeaders(['Accept-Language' => 'ja,ko;q=0.8'])
        ->get("/l/{$slug}")
        ->assertInertia(fn ($page) => $page->where('language', 'en'));
});

/**
 * The whole point of a URL per language: a link shared in Spanish opens in
 * Spanish, whatever the reader's browser prefers.
 */
it('lets an explicit ?lang beat the browser, and remembers it', function () {
    $slug = publishBilingual($this);

    $response = $this->withHeaders(['Accept-Language' => 'en-US,en;q=0.9'])
        ->get("/l/{$slug}?lang=es")
        ->assertInertia(fn ($page) => $page->where('language', 'es'));

    $response->assertCookie(LandingLanguages::COOKIE, 'es');

    // …and the choice survives the next visit, with no ?lang and an English browser.
    $this->withHeaders(['Accept-Language' => 'en-US,en;q=0.9'])
        ->withCookie(LandingLanguages::COOKIE, 'es')
        ->get("/l/{$slug}")
        ->assertInertia(fn ($page) => $page->where('language', 'es'));
});

it('does not remember a language the visitor never chose', function () {
    $slug = publishBilingual($this);

    $this->withHeaders(['Accept-Language' => 'es'])
        ->get("/l/{$slug}")
        ->assertCookieMissing(LandingLanguages::COOKIE);
});

/**
 * Without this the first visitor's language is cached and served to everyone
 * behind the same CDN entry — the classic silent failure of header negotiation.
 */
it('varies on the headers it negotiates with, without dropping what was there', function () {
    $slug = publishBilingual($this);

    $vary = $this->get("/l/{$slug}")->headers->get('Vary');

    expect($vary)->toContain('Accept-Language')->and($vary)->toContain('Cookie')
        // Inertia sets X-Inertia here; replacing Vary instead of adding to it
        // would trade a caching bug for a subtler one.
        ->and($vary)->toContain('X-Inertia');
});

it('sends one language to the browser, not every language', function () {
    $slug = publishBilingual($this);

    $this->withHeaders(['Accept-Language' => 'es'])
        ->get("/l/{$slug}")
        ->assertInertia(fn ($page) => $page->missing('page.blocks.0.content_i18n'))
        ->assertDontSee('Hello', escape: false);
});

it('falls back per block, so a half-translated page still reads', function () {
    $this->manifests->createVersion($this->app_, [
        ...bilingualManifest($this->app_),
        'pages' => [[
            'id' => 'pg_home', 'slug' => 'home', 'path' => '/', 'name' => 'Home',
            'blocks' => [
                ['id' => 'blk_hero', 'type' => 'html', 'content' => '<section><h1>Hello</h1></section>', 'content_i18n' => ['es' => '<section><h1>Hola</h1></section>']],
                ['id' => 'blk_foot', 'type' => 'html', 'content' => '<footer>Untranslated</footer>'],
            ],
        ]],
    ], $this->user);
    $slug = app(LandingPublisher::class)->publish($this->app_->fresh())['public_slug'];

    $this->withHeaders(['Accept-Language' => 'es'])
        ->get("/l/{$slug}")
        ->assertInertia(fn ($page) => $page
            ->where('page.blocks.0.content', '<section><h1>Hola</h1></section>')
            ->where('page.blocks.1.content', '<footer>Untranslated</footer>'));
});

it('points the two versions at each other for crawlers', function () {
    $slug = publishBilingual($this);

    $this->get("/l/{$slug}")->assertInertia(fn ($page) => $page
        ->where('alternates.0.lang', 'en')
        ->where('alternates.1.lang', 'es')
        // The default language IS the negotiating URL; the other carries ?lang.
        ->where('alternates.0.href', config('app.url')."/l/{$slug}")
        ->where('alternates.1.href', config('app.url')."/l/{$slug}?lang=es"));
});

it('translates the title and description too', function () {
    $slug = publishBilingual($this, [
        'seo' => ['title' => 'Agents that work', 'description' => 'English pitch'],
        'seo_i18n' => ['es' => ['title' => 'Agentes que trabajan', 'description' => 'Pitch en español']],
    ]);

    $this->withHeaders(['Accept-Language' => 'es'])
        ->get("/l/{$slug}")
        ->assertInertia(fn ($page) => $page
            ->where('seo.title', 'Agentes que trabajan')
            ->where('seo.description', 'Pitch en español'));
});

/**
 * A translation is markup from the same author reaching the same public page —
 * it passes the same boundary, or adding a language buys a sanitiser bypass.
 */
it('sanitises a translation exactly like the default content', function () {
    $this->manifests->createVersion($this->app_, bilingualManifest($this->app_, [], [
        'es' => '<section onclick="steal()"><script>alert(1)</script><h1 style="color:red">Hola</h1></section>',
    ]), $this->user);

    $stored = $this->manifests->getActiveManifest($this->app_->fresh())['pages'][0]['blocks'][0]['content_i18n']['es'];

    expect($stored)->not->toContain('script')
        ->and($stored)->not->toContain('onclick')
        ->and($stored)->not->toContain('style=')
        ->and($stored)->toContain('<h1>Hola</h1>');
});

it('leaves a single-language landing exactly as it was', function () {
    $this->manifests->createVersion($this->app_, bilingualManifest($this->app_, ['languages' => []], []), $this->user);
    $slug = app(LandingPublisher::class)->publish($this->app_->fresh())['public_slug'];

    // Nothing to negotiate, so nothing is added to Vary either.
    expect($this->get("/l/{$slug}")->headers->get('Vary'))->not->toContain('Accept-Language');

    $this->withHeaders(['Accept-Language' => 'es'])
        ->get("/l/{$slug}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('language', '')
            ->where('alternates', [])
            ->where('page.blocks.0.content', '<section id="top"><h1>Hello</h1></section>'));
});

// --- the rails: a translation that could never be served is refused ---

it('refuses a translation for a language the landing does not declare', function () {
    expect(fn () => $this->manifests->createVersion(
        $this->app_,
        bilingualManifest($this->app_, [], ['fr' => '<section><h1>Bonjour</h1></section>']),
        $this->user,
    ))->toThrow(InvalidManifestException::class, 'not in settings.languages');
});

it('refuses a translation keyed with the default language', function () {
    expect(fn () => $this->manifests->createVersion(
        $this->app_,
        bilingualManifest($this->app_, [], ['en' => '<section><h1>Hello again</h1></section>']),
        $this->user,
    ))->toThrow(InvalidManifestException::class, 'belongs in `content`');
});

it('refuses translations when no languages are declared at all', function () {
    expect(fn () => $this->manifests->createVersion(
        $this->app_,
        bilingualManifest($this->app_, ['languages' => []]),
        $this->user,
    ))->toThrow(InvalidManifestException::class, 'declares no languages');
});

it('refuses translations outside a landing surface, where nothing would serve them', function () {
    expect(fn () => $this->manifests->createVersion(
        $this->app_,
        bilingualManifest($this->app_, ['surface' => 'app']),
        $this->user,
    ))->toThrow(InvalidManifestException::class, 'only served on a landing surface');
});

/**
 * The headless render is how a translation gets REVIEWED before it ships — the
 * design director's eyes, and `render_landing`'s. Without a language it only
 * ever shows the default, and a translation ships unseen.
 */
it('renders the requested language for review, before anything is published', function () {
    $this->manifests->createVersion($this->app_, bilingualManifest($this->app_), $this->user);

    $url = URL::temporarySignedRoute('landing.render', now()->addMinutes(5), [
        'app' => $this->app_->id,
        'org' => $this->user->organization_id,
        'uid' => $this->user->id,
        'lang' => 'es',
    ]);

    $this->get($url)->assertOk()->assertInertia(fn ($page) => $page
        ->where('language', 'es')
        ->where('page.blocks.0.content', '<section id="top"><h1>Hola</h1></section>'));
});
