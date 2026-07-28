<?php

use Database\Seeders\RolesAndPermissionsSeeder;

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
 * A page wider than its own viewport is what produces a sideways scroll.
 * Reported as the offending width so a failure names the number, not just
 * "false is not true".
 */
const NO_HORIZONTAL_OVERFLOW = <<<'JS'
function () {
    const el = document.documentElement;
    return el.scrollWidth <= el.clientWidth;
}
JS;

dataset('viewports', [
    'phone (390)' => 'iPhone15',
    'tablet (768)' => 'iPadMini',
    'desktop (1440)' => 'macbookAir',
]);

dataset('auth screens', [
    '/login',
    '/register',
    '/forgot-password',
]);

it('never scrolls sideways on auth screens', function (string $route, string $device) {
    visit($route)->on()->{$device}()
        ->assertNoJavaScriptErrors()
        ->assertScript(NO_HORIZONTAL_OVERFLOW);
})->with('auth screens')->with('viewports');

dataset('shell screens', [
    '/apps',
    '/agents',
    '/tools',
]);

it('never scrolls sideways inside the app shell', function (string $route, string $device) {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(mcpMember(mcpOrg()));

    visit($route)->on()->{$device}()
        ->assertNoJavaScriptErrors()
        ->assertScript(NO_HORIZONTAL_OVERFLOW);
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
