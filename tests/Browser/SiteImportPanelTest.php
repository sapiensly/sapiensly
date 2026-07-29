<?php

use App\Models\OrganizationAiContext;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * The import panel is the whole feature: what it says before you press the
 * button, and what it says when the read does not work. Both are things a user
 * reads on screen, so they are worth driving in a real browser rather than
 * asserting on JSON.
 */
function siteImportOwner(?string $website = null): void
{
    test()->seed(RolesAndPermissionsSeeder::class);

    $org = mcpOrg();
    test()->actingAs(mcpMember($org));

    if ($website !== null) {
        OrganizationAiContext::firstOrNew(['organization_id' => $org->id])
            ->setRelation('organization', $org)
            ->fill(['profile' => ['website' => $website]])
            ->recompile()
            ->save();
    }
}

/** The UI renders in the request locale; the labels asserted here must come from it. */
function importLabel(string $key): string
{
    $file = resource_path('js/locales/'.app()->getLocale().'.json');
    $messages = json_decode(
        (string) file_get_contents(file_exists($file) ? $file : resource_path('js/locales/en.json')),
        true,
    );

    return $messages[$key] ?? $key;
}

/**
 * The Brandbook has no website of its own. Asking for it again, on the second of
 * two pages that read the same site, is asking twice for the same fact.
 */
it('starts the brandbook import from the website the contextbook knows', function () {
    siteImportOwner('https://acme.example');

    $typed = <<<'JS'
    function () {
        return document.querySelector('input[inputmode="url"]')?.value ?? '';
    }
    JS;

    visit('/settings/organization/brand')->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertScript($typed, 'https://acme.example');
});

/**
 * The papercut the whole flow was rebuilt around: `acme.com` used to fail
 * validation and come back as "that site could not be read".
 */
it('says which address it will actually read', function () {
    siteImportOwner();

    visit('/settings/organization/brand')->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->type('input[inputmode="url"]', 'acme.example')
        ->assertSee(str_replace('{url}', 'https://acme.example', importLabel('site_import.will_read')));
});

/**
 * Four ways a read fails, four different fixes. One message for all of them is
 * what the two separate flows used to give.
 */
it('names the problem instead of blaming the site', function () {
    siteImportOwner();

    visit('/settings/organization/brand')->on()->macbookAir()
        ->type('input[inputmode="url"]', 'javascript:alert(1)')
        ->click(importLabel('site_import.read'))
        ->assertSee(importLabel('site_import.reason.invalid_url'))
        ->assertNoJavaScriptErrors();
});

/** One URL on the Contextbook page: the stored field and the import are the same box. */
it('drafts the contextbook from the website field itself', function () {
    siteImportOwner('https://acme.example');

    $boxes = <<<'JS'
    function () {
        return String(document.querySelectorAll('input[inputmode="url"]').length);
    }
    JS;

    visit('/settings/organization/context')->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertScript($boxes, '1')
        ->assertSee(importLabel('site_import.read'));
});
