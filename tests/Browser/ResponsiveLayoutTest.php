<?php

use App\Models\AiUsageEvent;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;

/*
|--------------------------------------------------------------------------
| The responsive contract
|--------------------------------------------------------------------------
|
| Every screen in scope must survive the three widths we certify without
| spilling sideways. A page that scrolls horizontally on a phone is the most
| common way a desktop-only layout fails, and it is the cheapest to catch —
| so it is asserted here rather than eyeballed once and forgotten.
|
| Scope (agreed): the admin-v2 shell and the auth screens. Public landings
| and the legacy layouts are deliberately out.
|
| Devices map to our three targets exactly:
|   iPhone15    390 x 844   phone
|   iPadMini    768 x 1024  tablet
|   macbookAir 1440 x 900   desktop (any width >= the `lg` boundary)
|
*/

/**
 * Content that runs past the right edge of the viewport.
 *
 * NOT `scrollWidth > clientWidth`: the shell's root is `overflow-hidden`
 * (AppLayoutV2), so anything that spills is silently CLIPPED instead of
 * making the document scroll. That check passes on a page whose buttons and
 * cards are sliced off — which is exactly what it did before this was
 * rewritten. Measuring each element's right edge against the viewport is what
 * actually catches it.
 *
 * Skipped: absolutely/fixed-positioned decoration (glows deliberately clipped
 * by overflow-hidden) and anything inside a real horizontal scroller, which
 * is a legitimate way to present a wide table.
 *
 * Returns the offender so a failure names it instead of saying "false".
 */
const NO_CLIPPED_CONTENT = <<<'JS'
function () {
    const vw = document.documentElement.clientWidth;
    // Stops at <main>: the shell's scroll container is `overflow-y-auto`, and
    // CSS computes the other axis to `auto` whenever one axis is not visible.
    // Walking past it would treat EVERY element as "inside a scroller" and
    // wave the whole page through — which is what it did at first.
    const inScroller = (el) => {
        for (let p = el.parentElement; p && p.tagName !== 'MAIN' && p !== document.body; p = p.parentElement) {
            const ox = getComputedStyle(p).overflowX;
            if (ox === 'auto' || ox === 'scroll') return true;
        }
        return false;
    };
    for (const el of document.querySelectorAll('body *')) {
        const cs = getComputedStyle(el);
        if (cs.position === 'absolute' || cs.position === 'fixed') continue;
        if (cs.display === 'none' || cs.visibility === 'hidden' || cs.pointerEvents === 'none') continue;
        const r = el.getBoundingClientRect();
        if (r.width === 0 || r.height === 0) continue;
        if (r.right > vw + 1 && !inScroller(el)) {
            return `${el.tagName}.${(el.className || '').toString().slice(0, 70)} right=${Math.round(r.right)} vw=${vw}`;
        }
    }
    return '';
}
JS;

dataset('viewports', [
    'phone (390)' => 'iPhone15',
    'tablet (768)' => 'iPadMini',
    'desktop (1440)' => 'macbookAir',
]);

/** The auth screens a guest can reach directly. */
dataset('auth screens', [
    '/login',
    '/register',
    '/forgot-password',
    // Any token renders the form; Fortify only validates it on submit.
    '/reset-password/2f6c9a1e-token',
]);

it('never scrolls sideways on auth screens', function (string $route, string $device) {
    visit($route)->on()->{$device}()
        ->assertNoJavaScriptErrors()
        ->assertScript(NO_CLIPPED_CONTENT, '');
})->with('auth screens')->with('viewports');

/*
 * The remaining three auth screens are unreachable as a guest — each needs a
 * specific session state, so they get the setup rather than being quietly
 * dropped from the sweep.
 */

it('never scrolls sideways on the email verification notice', function (string $device) {
    $this->actingAs(User::factory()->unverified()->create());

    visit('/email/verify')->on()->{$device}()
        ->assertNoJavaScriptErrors()
        ->assertScript(NO_CLIPPED_CONTENT, '');
})->with('viewports');

it('never scrolls sideways on the password confirmation screen', function (string $device) {
    $this->actingAs(User::factory()->create());

    visit('/user/confirm-password')->on()->{$device}()
        ->assertNoJavaScriptErrors()
        ->assertScript(NO_CLIPPED_CONTENT, '');
})->with('viewports');

it('never scrolls sideways on the two-factor challenge', function (string $device) {
    // Reached only by logging in as a user with 2FA confirmed — Fortify puts
    // the pending id in the session, so it cannot be visited directly.
    $user = User::factory()->create([
        'email' => 'two-factor@example.com',
        'password' => Hash::make('password'),
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb'])),
        'two_factor_confirmed_at' => now(),
    ]);

    visit('/login')->on()->{$device}()
        ->fill('email', $user->email)
        ->fill('password', 'password')
        // By selector, not by label: "Log in" is both the card heading and the
        // button, and the text locator resolves to the heading.
        ->click('button[type="submit"]')
        ->assertPathIs('/two-factor-challenge')
        ->assertNoJavaScriptErrors()
        ->assertScript(NO_CLIPPED_CONTENT, '');
})->with('viewports');

/**
 * Every parameter-free page the admin-v2 shell serves. The phone width is
 * where layouts fail, so the whole surface is swept at 390 rather than a
 * hand-picked sample — the point is to find the screens nobody thought to
 * check.
 */
dataset('shell screens', [
    '/apps',
    '/agents',
    '/agents/create',
    '/chat',
    '/chatbots',
    '/chatbots/create',
    '/dashboard',
    '/debates',
    '/documents',
    '/documents/create',
    '/knowledge-bases',
    '/knowledge-bases/create',
    '/playground',
    '/playground/benchmarks',
    '/playground/history',
    '/settings/profile',
    '/settings/appearance',
    '/settings/organization',
    '/settings/organization/brand',
    '/settings/organization/context',
    '/settings/sso',
    '/slides',
    '/system/ai-providers',
    '/system/ai-providers/create',
    '/system/ai-spend',
    '/system/cloud-providers',
    '/system/integrations',
    '/system/integrations/create',
    '/system/integrations/templates',
    '/system/mcp',
    '/system/whatsapp',
    '/system/whatsapp/create',
    '/tools',
    '/tools/create',
]);

/**
 * Guards the sweep below. If seeding ever stops producing visible rows —
 * a renamed column, a visibility rule, a factory change — the sweep would go
 * back to certifying empty states and stay green while testing nothing.
 * This is the canary for that.
 */
it('renders seeded rows on the list screens', function (string $route) {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = mcpMember($org = mcpOrg());
    $this->actingAs($user);
    seedTenantContent($org, $user);

    visit($route)->on()->macbookAir()
        ->assertSee('Warehouse Inventory Reconciliation Assistant');
})->with([
    '/agents',
    '/tools',
    '/chatbots',
    '/knowledge-bases',
    '/apps',
    '/documents',
    '/system/integrations',
    '/chat',
]);

it('never scrolls sideways inside the app shell', function (string $route, string $device) {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = mcpMember($org = mcpOrg());
    $this->actingAs($user);

    // With rows in them — an empty list is responsive by construction and
    // would make this sweep pass without testing anything.
    seedTenantContent($org, $user);

    visit($route)->on()->{$device}()
        ->assertNoJavaScriptErrors()
        ->assertScript(NO_CLIPPED_CONTENT, '');
})->with('shell screens')->with('viewports');

/*
|--------------------------------------------------------------------------
| The shell's navigation
|--------------------------------------------------------------------------
|
| Below `lg` the sidebar column is not rendered, so the drawer IS the only
| navigation there — if it stops opening, the app becomes unnavigable on a
| phone without anything visibly breaking. Hence these are asserted, not
| eyeballed.
|
*/

const SIDEBAR_COLUMN_HIDDEN = <<<'JS'
function () {
    const aside = document.querySelector('aside.sp-glass-sidebar');
    return aside !== null && getComputedStyle(aside).display === 'none';
}
JS;

const SIDEBAR_COLUMN_SHOWN = <<<'JS'
function () {
    const aside = document.querySelector('aside.sp-glass-sidebar');
    return aside !== null && aside.getBoundingClientRect().width > 0;
}
JS;

it('swaps the sidebar column for a drawer below lg', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(mcpMember(mcpOrg()));

    visit('/apps')->on()->iPhone15()
        ->assertScript(SIDEBAR_COLUMN_HIDDEN)
        // Nothing is mounted until asked for — no overlay stealing taps.
        ->assertScript("document.querySelector('.sp-mobile-nav') === null")
        ->click('[aria-label="Open navigation"]')
        ->assertPresent('.sp-mobile-nav')
        ->assertSee('Knowledge Base')
        ->assertNoJavaScriptErrors();
});

it('closes the drawer when navigating, leaving no overlay behind', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(mcpMember(mcpOrg()));

    visit('/apps')->on()->iPhone15()
        ->click('[aria-label="Open navigation"]')
        ->assertPresent('.sp-mobile-nav')
        ->click('.sp-mobile-nav a[href="/chatbots"]')
        ->assertPathIs('/chatbots')
        ->assertScript("document.querySelector('.sp-mobile-nav') === null")
        // A stranded dialog overlay leaves the body unclickable — the failure
        // mode that makes a page look fine and respond to nothing.
        ->assertScript("getComputedStyle(document.body).pointerEvents === 'auto'")
        ->assertNoJavaScriptErrors();
});

/*
|--------------------------------------------------------------------------
| Screens with data in them
|--------------------------------------------------------------------------
|
| The sweeps above visit a fresh tenant, so most screens render their empty
| state — and an empty state is responsive by construction. Anything with a
| table or a list has to be asserted with rows actually in it, or the check
| is theatre.
|
*/

it('scrolls the spend table on a phone instead of crushing its columns', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = mcpMember($org = mcpOrg());
    $this->actingAs($user);

    // Long, realistic model identifiers — the reason the column needs room.
    // The org dashboard reads raw events, not the daily rollup.
    foreach (['anthropic/claude-opus-5-20260514', 'openai/gpt-5-turbo-preview'] as $model) {
        AiUsageEvent::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'module' => 'chat',
            'driver' => 'anthropic',
            'model' => $model,
            'source' => 'system',
            'input_tokens' => 4_500_000,
            'output_tokens' => 900_000,
            'cost' => 123.45,
        ]);
    }

    visit('/system/ai-spend')->on()->iPhone15()
        ->assertSee('anthropic/claude-opus-5-20260514')
        // The page itself must still not spill past the viewport...
        ->assertScript(NO_CLIPPED_CONTENT, '')
        // ...while the table keeps its readable width inside a scroller.
        ->assertScript(<<<'JS'
            function () {
                const table = document.querySelector('table');
                const scroller = table.parentElement;
                return table.getBoundingClientRect().width >= 420
                    && ['auto', 'scroll'].includes(getComputedStyle(scroller).overflowX);
            }
            JS)
        ->assertNoJavaScriptErrors();
});

it('keeps the persistent sidebar column at lg and above', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(mcpMember(mcpOrg()));

    visit('/apps')->on()->macbookAir()
        ->assertScript(SIDEBAR_COLUMN_SHOWN)
        ->assertScript("document.querySelector('.sp-mobile-nav') === null")
        // Asserted by href, not by label: with no `sidebar_state` cookie the
        // column starts as the collapsed icon rail, so the labels are absent
        // on a first visit even though the nav is fully there.
        ->assertPresent('aside.sp-glass-sidebar a[href="/knowledge-bases"]')
        ->assertNoJavaScriptErrors();
});
