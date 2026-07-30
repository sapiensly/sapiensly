<?php

use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * The unified identity screen, driven in a real browser: two tabs over the
 * general facts, the tab the address asks for, and state that survives a switch.
 *
 * These are the three things the split into two pages made impossible, and none
 * of them can be asserted on JSON.
 */
function identityOwner(): void
{
    test()->seed(RolesAndPermissionsSeeder::class);
    test()->actingAs(mcpMember(mcpOrg()));
}

/** Which tab is selected, read off the tablist rather than off hidden text. */
const SELECTED_TAB = <<<'JS'
function () {
    return document
        .querySelector('[role="tab"][aria-selected="true"]')
        ?.textContent.trim() ?? '';
}
JS;

it('puts both books behind tabs over the general facts', function () {
    identityOwner();

    $urlBoxes = <<<'JS'
    function () {
        return String(document.querySelectorAll('input[inputmode="url"]').length);
    }
    JS;

    visit('/settings/organization/identity')->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        // The general facts and the one URL both books are read from.
        ->assertSee('Identity')
        ->assertScript($urlBoxes, '1')
        // Both books are reachable from here, and the Brandbook opens first.
        ->assertSee('Brandbook')
        ->assertSee('Contextbook')
        ->assertScript(SELECTED_TAB, 'Brandbook');
});

/** The old per-book addresses redirect with ?tab=; landing on the wrong book is a bug. */
it('opens the tab the address asks for', function () {
    identityOwner();

    visit('/settings/organization/identity?tab=context')->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertScript(SELECTED_TAB, 'Contextbook');
});

/**
 * Both panels stay mounted: switching tabs must not throw away what someone
 * typed — or an upload in flight — on the tab they left.
 */
it('keeps what was typed when the other book is opened', function () {
    identityOwner();

    $currency = <<<'JS'
    function () {
        return document.querySelector('input[placeholder="USD"]')?.value ?? '';
    }
    JS;

    visit('/settings/organization/identity?tab=context')->on()->macbookAir()
        ->type('input[placeholder="USD"]', 'MXN')
        ->click('Brandbook')
        ->assertScript(SELECTED_TAB, 'Brandbook')
        ->click('Contextbook')
        ->assertScript($currency, 'MXN')
        ->assertNoJavaScriptErrors();
});
