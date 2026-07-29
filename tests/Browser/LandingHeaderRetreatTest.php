<?php

use App\Models\App;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/**
 * `data-sp-hide-while` — a fixed header steps aside while the footer is on
 * screen and comes back when the reader scrolls up off it.
 *
 * Driven in a real browser because the whole effect IS scroll geometry: an
 * IntersectionObserver against the viewport, on an element whose position is
 * fixed. Nothing about that is observable from PHP.
 *
 * The assertions read the inline styles the runtime commits rather than the
 * rendered rect, because the transition is still in flight a frame after the
 * observer fires — the commitment is the behaviour; the 280ms easing is taste.
 */
function retreatingHeaderApp(): string
{
    $user = mcpMember($org = mcpOrg());
    test()->actingAs($user);

    // The runtime route only accepts `[a-z][a-z0-9_]*`, and the factory's slug
    // is hyphenated — left to itself this 404s.
    $app = App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'slug' => 'retreat_'.strtolower((string) Str::ulid()),
    ]);
    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id, 'slug' => 'retreat', 'name' => 'Retreat', 'version' => 1,
        'objects' => [],
        'pages' => [[
            'id' => 'pag_'.strtolower((string) Str::ulid()), 'slug' => 'home', 'name' => 'Home', 'path' => '/',
            'blocks' => [[
                'id' => 'blk_'.strtolower((string) Str::ulid()), 'type' => 'html',
                'content' => '<header class="hdr" data-sp-hide-while="foot"><a href="#foot">Start</a></header>'
                    .'<section class="tall">Body</section>'
                    .'<footer class="ft" id="foot">Footer</footer>',
            ]],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
        'settings' => [
            'surface' => 'landing',
            'custom_css' => '.hdr{position:fixed;top:0;left:0;right:0;height:60px;background:#111;color:#fff}'
                .'.tall{height:3000px}.ft{height:600px;background:#000;color:#fff}',
        ],
    ], $user);

    return $app->slug;
}

/** What the runtime has committed on the header, transition aside. */
const HEADER_STATE = <<<'JS'
function () {
    const el = document.querySelector('[data-sp-hide-while]');
    if (!el) return 'missing';
    return (el.style.transform === 'translateY(-100%)' ? 'retreated' : 'in-place')
        + '/' + (el.style.pointerEvents || 'auto');
}
JS;

const SCROLL_TO_FOOTER = <<<'JS'
function () {
    window.scrollTo(0, document.body.scrollHeight);
    return 'scrolled';
}
JS;

const SCROLL_BACK_UP = <<<'JS'
function () {
    window.scrollTo(0, 0);
    return 'scrolled';
}
JS;

it('retreats a fixed header while the footer is in view and brings it back', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $slug = retreatingHeaderApp();

    // Each assertScript is its own round trip, which is what gives the
    // observer a frame to fire between the scroll and the reading.
    visit("/r/{$slug}")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertScript(HEADER_STATE, 'in-place/auto')
        ->assertScript(SCROLL_TO_FOOTER, 'scrolled')
        ->assertScript(HEADER_STATE, 'retreated/none')
        ->assertScript(SCROLL_BACK_UP, 'scrolled')
        ->assertScript(HEADER_STATE, 'in-place/auto');
});

// The reduced-motion branch (the header never hides, which is also what keeps
// the headless design shot from being judged with no header) is deliberately
// NOT asserted here: the browser plugin cannot emulate a media feature, and
// faking `matchMedia` after hydration would test the fake, not the runtime.
