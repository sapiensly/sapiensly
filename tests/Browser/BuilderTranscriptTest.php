<?php

use App\Models\App;
use App\Models\BuilderConversation;
use App\Models\BuilderMessage;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/**
 * An import prompt carries the page it is reproducing — tens of KB of rendered
 * DOM and CSS — and the transcript used to render every byte, burying the
 * conversation. The content still has to BE there (it is the prompt the model
 * answered, and it reads it back from the stored message on later turns), so
 * only the presentation changes.
 */
function transcriptAppWithPayload(): string
{
    $user = mcpMember($org = mcpOrg());
    test()->actingAs($user);

    $app = App::factory()->create(['user_id' => $user->id, 'organization_id' => $org->id]);
    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id, 'slug' => 'payload', 'name' => 'Payload', 'version' => 1,
        'objects' => [], 'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
    ], $user);

    $conv = BuilderConversation::create([
        'app_id' => $app->id, 'user_id' => $user->id, 'organization_id' => $org->id, 'status' => 'active',
    ]);

    BuilderMessage::create([
        'conversation_id' => $conv->id, 'role' => 'user', 'status' => 'none',
        'content' => "Reconstruye esta landing lo más fiel posible.\n\n```html\n"
            .str_repeat('<section class="hero"><h1>Build your first agent</h1></section>', 60)
            ."\n```\n\nMantén el copy literal.",
    ]);

    return $app->id;
}

it('collapses an import payload instead of printing it into the transcript', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $appId = transcriptAppWithPayload();

    $shape = <<<'JS'
    function () {
        const d = document.querySelector('details');
        if (!d) return 'no details';
        if (d.open) return 'open by default';
        // The prose around the payload must still read as prose.
        const body = document.body.innerText;
        if (!body.includes('Reconstruye esta landing')) return 'prose missing';
        if (!body.includes('Mantén el copy literal')) return 'trailing prose missing';
        // Collapsed: the markup must not be sitting in the visible text.
        if (body.includes('Build your first agent')) return 'payload still printed';
        // …but it must still be in the DOM, because it is the prompt.
        return d.querySelector('pre')?.textContent?.includes('Build your first agent')
            ? 'collapsed' : 'payload lost';
    }
    JS;

    visit("/apps/{$appId}/builder")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertScript($shape, 'collapsed');
});

it('labels the collapsed payload with its language and size', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $appId = transcriptAppWithPayload();

    $label = <<<'JS'
    function () { const s = document.querySelector('details summary'); return s ? s.textContent.trim() : 'none'; }
    JS;

    // 60 copies of a ~60-byte section: a few KB, named so the reader can decide.
    visit("/apps/{$appId}/builder")->on()->macbookAir()
        ->assertScript($label, 'HTML · 4 KB');
});

it('leaves a short snippet inline', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = mcpMember($org = mcpOrg());
    $this->actingAs($user);

    $app = App::factory()->create(['user_id' => $user->id, 'organization_id' => $org->id]);
    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id, 'slug' => 'snippet', 'name' => 'Snippet', 'version' => 1,
        'objects' => [], 'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
    ], $user);
    $conv = BuilderConversation::create([
        'app_id' => $app->id, 'user_id' => $user->id, 'organization_id' => $org->id, 'status' => 'active',
    ]);
    BuilderMessage::create([
        'conversation_id' => $conv->id, 'role' => 'user', 'status' => 'none',
        'content' => "Usa este color:\n```css\n.hero{color:#0096FF}\n```",
    ]);

    // Hiding a two-line snippet behind a toggle would be worse than showing it.
    visit("/apps/{$app->id}/builder")->on()->macbookAir()
        ->assertScript('function () { return document.querySelector("details") ? "collapsed" : "inline"; }', 'inline')
        ->assertSee('.hero{color:#0096FF}');
});

it('lets a phone scroll down to the preview', function () {
    // AppLayoutV2 only gives <main> overflow-y-auto when NOT full-bleed, and the
    // builder is full-bleed inside an h-screen overflow-hidden shell. Adding
    // min-heights to the stacked panes made the content taller than the viewport
    // with nothing able to scroll it: the live preview was simply unreachable.
    $this->seed(RolesAndPermissionsSeeder::class);
    $appId = transcriptAppWithPayload();

    $reachable = <<<'JS'
    function () {
        const scroller = [...document.querySelectorAll('div')]
            .find(d => d.scrollHeight > d.clientHeight + 40 && ['auto','scroll'].includes(getComputedStyle(d).overflowY) && d.querySelector('section'));
        if (!scroller) return 'nothing scrolls';
        scroller.scrollTop = scroller.scrollHeight;
        // The work pane is the second stacked section; reaching the bottom must
        // actually bring it into the viewport.
        const panes = [...document.querySelectorAll('section')].filter(s => s.className.includes('bg-navy'));
        if (panes.length < 2) return 'panes=' + panes.length;
        const box = panes[1].getBoundingClientRect();
        return box.top < window.innerHeight ? 'reachable' : 'still below the fold';
    }
    JS;

    visit("/apps/{$appId}/builder")->on()->iPhone15()
        ->assertNoJavaScriptErrors()
        ->assertScript($reachable, 'reachable');
});

it('drops the revert label on a phone and keeps it on desktop', function () {
    // Badge + button + "view patch" in one row: with its label the revert button
    // rode over the "applied" badge next to it (reported with a screenshot).
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = mcpMember($org = mcpOrg());
    $this->actingAs($user);

    $app = App::factory()->create(['user_id' => $user->id, 'organization_id' => $org->id]);
    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id, 'slug' => 'reverted', 'name' => 'Reverted', 'version' => 1,
        'objects' => [], 'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
    ], $user);
    $conv = BuilderConversation::create([
        'app_id' => $app->id, 'user_id' => $user->id, 'organization_id' => $org->id, 'status' => 'active',
    ]);
    BuilderMessage::create([
        'conversation_id' => $conv->id, 'role' => 'assistant', 'status' => 'applied',
        'content' => 'Listo.', 'change_summary' => 'Añadí el hero',
        'proposed_patch' => [['op' => 'replace', 'path' => '/name', 'value' => 'Reverted']],
    ]);

    // Icon-only, but still named for anyone not looking at it.
    $label = <<<'JS'
    function () {
        const b = [...document.querySelectorAll('button')].find(x => (x.getAttribute('aria-label') || '').match(/Deshacer|Revert|Undo/i));
        if (!b) return 'no button';
        const span = b.querySelector('span');
        return span && span.getBoundingClientRect().width > 0 ? 'labelled' : 'icon-only';
    }
    JS;

    visit("/apps/{$app->id}/builder")->on()->iPhone15()->assertScript($label, 'icon-only');
    visit("/apps/{$app->id}/builder")->on()->macbookAir()->assertScript($label, 'labelled');
});

function landingPreviewApp(): string
{
    $user = mcpMember($org = mcpOrg());
    test()->actingAs($user);

    $app = App::factory()->create(['user_id' => $user->id, 'organization_id' => $org->id]);
    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id, 'slug' => 'lp', 'name' => 'LP', 'version' => 1,
        'objects' => [],
        'pages' => [[
            'id' => 'pag_'.strtolower((string) Str::ulid()), 'slug' => 'home', 'name' => 'Home', 'path' => '/',
            'blocks' => [[
                'id' => 'blk_'.strtolower((string) Str::ulid()), 'type' => 'html',
                'content' => '<section class="hero"><h1>Build your first agent</h1></section>',
            ]],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
        'settings' => ['surface' => 'landing', 'custom_css' => '.hero{padding:4rem 2rem;background:#00031C;color:#fff}'],
    ], $user);

    return $app->id;
}

it('previews a landing edge to edge on a phone', function () {
    // Measured before this: the preview body's p-5 plus the page gutter rendered
    // a 390px landing at 324 — 17% of the width gone, and the design's own
    // breakpoints resolving against a width no visitor will ever have.
    $this->seed(RolesAndPermissionsSeeder::class);
    $appId = landingPreviewApp();

    $surfaceWidth = <<<'JS'
    function () {
        const s = document.querySelector('.sp-app-surface');
        if (!s) return 'no surface';
        const r = s.getBoundingClientRect();
        return (Math.round(r.width) === document.documentElement.clientWidth && Math.round(r.left) === 0)
            ? 'full-bleed' : `inset w=${Math.round(r.width)} x=${Math.round(r.left)}`;
    }
    JS;

    visit("/apps/{$appId}/builder")->on()->iPhone15()
        ->assertNoJavaScriptErrors()
        ->assertScript($surfaceWidth, 'full-bleed');
});

it('keeps the preview framed on a desktop', function () {
    // The inset is chrome, and at a width that can afford it it reads as chrome.
    $this->seed(RolesAndPermissionsSeeder::class);
    $appId = landingPreviewApp();

    $framed = <<<'JS'
    function () {
        const s = document.querySelector('.sp-app-surface');
        if (!s) return 'no surface';
        return s.getBoundingClientRect().left > 0 ? 'framed' : 'flush';
    }
    JS;

    visit("/apps/{$appId}/builder")->on()->macbookAir()->assertScript($framed, 'framed');
});

function motionHooksApp(): string
{
    $user = mcpMember($org = mcpOrg());
    test()->actingAs($user);

    $app = App::factory()->create(['user_id' => $user->id, 'organization_id' => $org->id]);
    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id, 'slug' => 'hooks', 'name' => 'Hooks', 'version' => 1,
        'objects' => [],
        'pages' => [[
            'id' => 'pag_'.strtolower((string) Str::ulid()), 'slug' => 'home', 'name' => 'Home', 'path' => '/',
            'blocks' => [[
                'id' => 'blk_'.strtolower((string) Str::ulid()), 'type' => 'html',
                'content' => '<section class="hero"><a id="hero-cta" href="#x">Build your first agent</a></section>'
                    .'<div id="sticky" data-sp-sticky-after="hero-cta">Sticky CTA</div>'
                    .'<div id="seq" data-sp-sequence="40"><p>uno</p><p>dos</p><p>tres</p></div>'
                    .'<button data-sp-replay="seq">Replay</button>',
            ]],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
        'settings' => ['surface' => 'landing', 'custom_css' => '.hero{padding:2rem}'],
    ], $user);

    return $app->id;
}

it('keeps a sticky-after element hidden AND inert until its anchor leaves', function () {
    // The header CTA that appears past the hero. Invisible is not enough: an
    // unreachable control must not still be clickable or focusable.
    $this->seed(RolesAndPermissionsSeeder::class);
    $appId = motionHooksApp();

    $state = <<<'JS'
    function () {
        const el = document.querySelector('[data-sp-sticky-after]');
        if (!el) return 'missing';
        const cs = getComputedStyle(el);
        return `${cs.opacity}/${cs.pointerEvents}`;
    }
    JS;

    visit("/apps/{$appId}/builder")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertScript($state, '0/none');
});

it('wires a replay control to restart its sequence', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $appId = motionHooksApp();

    // Clicking resets the children to the start — without that a "replay" on an
    // already-played sequence is a button that does nothing.
    $replay = <<<'JS'
    function () {
        const btn = document.querySelector('[data-sp-replay]');
        const seq = document.getElementById('seq');
        if (!btn || !seq) return 'missing';
        [...seq.children].forEach(k => { k.style.opacity = '1'; });
        btn.click();
        return [...seq.children].every(k => k.style.opacity === '0') ? 'restarted' : 'no-op';
    }
    JS;

    visit("/apps/{$appId}/builder")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertScript($replay, 'restarted');
});

it('collapses the header pills behind one control on a phone', function () {
    // Four rows of pills before the chat even starts. Closed by default, and
    // the toggle opens them — asserted through a real click, not by reading a
    // class, so a broken handler shows up as a failure.
    $this->seed(RolesAndPermissionsSeeder::class);
    $appId = landingPreviewApp();

    $pillsVisible = <<<'JS'
    function () {
        const btn = [...document.querySelectorAll('button')].find(b => /Opciones|Options/i.test(b.textContent || ''));
        if (!btn) return 'no toggle';
        const row = btn.nextElementSibling;
        if (!row) return 'no row';
        return getComputedStyle(row).display === 'none' ? 'collapsed' : 'open';
    }
    JS;

    $openIt = <<<'JS'
    function () {
        const btn = [...document.querySelectorAll('button')].find(b => /Opciones|Options/i.test(b.textContent || ''));
        btn.click();
        return getComputedStyle(btn.nextElementSibling).display !== 'none' ? 'open' : 'still closed';
    }
    JS;

    visit("/apps/{$appId}/builder")->on()->iPhone15()
        ->assertNoJavaScriptErrors()
        ->assertScript($pillsVisible, 'collapsed')
        ->assertScript($openIt, 'open');
});

it('leaves the header pills in place on a desktop', function () {
    // The toggle is a phone affordance; at a width that fits the row it must not
    // appear at all, and the pills must be there without a click.
    $this->seed(RolesAndPermissionsSeeder::class);
    $appId = landingPreviewApp();

    $desktop = <<<'JS'
    function () {
        const btn = [...document.querySelectorAll('button')].find(b => /Opciones|Options/i.test(b.textContent || ''));
        if (btn && btn.getBoundingClientRect().width > 0) return 'toggle showing';
        const pills = [...document.querySelectorAll('button')].filter(b => /Acento|Accent|Capas|Layers/i.test(b.textContent || ''));
        return pills.some(p => p.getBoundingClientRect().width > 0) ? 'pills visible' : 'pills hidden';
    }
    JS;

    visit("/apps/{$appId}/builder")->on()->macbookAir()->assertScript($desktop, 'pills visible');
});
