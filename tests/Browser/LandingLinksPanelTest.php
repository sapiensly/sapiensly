<?php

use App\Models\App;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/**
 * The Links panel is the answer to "where do my buttons go?" — a question about
 * the WHOLE page, which is why it groups by destination instead of listing
 * controls. These drive the real UI, because the value of the feature is in the
 * grouping the author sees, not in the endpoint underneath it.
 */
function landingWithLinks(): string
{
    $user = mcpMember($org = mcpOrg());
    test()->actingAs($user);

    $app = App::factory()->create(['user_id' => $user->id, 'organization_id' => $org->id]);
    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => 'links_landing',
        'name' => 'Links landing',
        'version' => 1,
        'objects' => [],
        'pages' => [[
            'id' => 'pg_home',
            'slug' => 'home',
            'path' => '/',
            'name' => 'Home',
            'blocks' => [
                ['id' => 'blk_header', 'type' => 'html', 'content' => '<header><a href="#waitlist">Empieza</a><a href="#pricing">Precios</a></header>'],
                ['id' => 'blk_pricing', 'type' => 'html', 'content' => '<section id="pricing"><a href="#waitlist">Gratis</a><a href="#waitlist">Pro</a></section>'],
                ['id' => 'blk_footer', 'type' => 'html', 'content' => '<footer><a href="#">Quickstart</a><button class="cta">Habla con nosotros</button></footer>'],
            ],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
        'settings' => ['surface' => 'landing'],
    ], $user);

    return $app->id;
}

it('lists every link grouped by destination, broken ones first', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $appId = landingWithLinks();

    // Three links point at #waitlist across two sections; the footer's
    // placeholder and its inert <button> both have no destination at all.
    $shape = <<<'JS'
    function () {
        const rows = [...document.querySelectorAll('[data-sp-links-panel] li > div > span.font-mono')];
        return rows.map((s) => s.textContent.trim()).join(' | ');
    }
    JS;

    $counts = <<<'JS'
    function () {
        const badges = [...document.querySelectorAll('[data-sp-links-panel] li > div > button')]
            .filter((b) => /^\s*\d+\s*$/.test(b.textContent));
        return badges.map((b) => b.textContent.trim()).join(',');
    }
    JS;

    visit("/apps/{$appId}/builder")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        // The header's controls are collapsed behind the options menu at every
        // width now, so fine-tune is one click deeper than it used to be.
        ->click(builderLabel('apps.builder.header_menu'))
        ->click(builderLabel('apps.builder.panel_mode_manual'))
        ->click(builderLabel('apps.builder.links_button'))
        ->assertSee(builderLabel('apps.builder.links_title'))
        // Sorted broken-first, then by size: no-destination (2), #waitlist (3),
        // #pricing (1).
        ->assertScript($shape, builderLabel('apps.builder.links_no_target').' | #waitlist | #pricing')
        ->assertScript($counts, '2,3,1');
});

it('warns that a <button> cannot navigate on a landing', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $appId = landingWithLinks();

    $warning = <<<'JS'
    function () {
        return document.querySelector('[data-sp-links-panel]')?.innerText.includes('<button>') ? 'warned' : 'silent';
    }
    JS;

    visit("/apps/{$appId}/builder")->on()->macbookAir()
        // The header's controls are collapsed behind the options menu at every
        // width now, so fine-tune is one click deeper than it used to be.
        ->click(builderLabel('apps.builder.header_menu'))
        ->click(builderLabel('apps.builder.panel_mode_manual'))
        ->click(builderLabel('apps.builder.links_button'))
        ->assertScript($warning, 'warned');
});

it('retargets a whole group from the panel', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $appId = landingWithLinks();

    // Open the #waitlist group's editor: the second "Cambiar" button, since the
    // no-destination group sorts above it.
    $openEditor = <<<'JS'
    function () {
        const buttons = [...document.querySelectorAll('[data-sp-links-panel] li > div > button')]
            .filter((b) => !/^\s*\d+\s*$/.test(b.textContent));
        if (buttons.length < 2) return 'missing';
        buttons[1].click();
        return 'opened';
    }
    JS;

    $fill = <<<'JS'
    function () {
        const input = document.querySelector('[data-sp-links-panel] input[list="sp-landing-anchors"]');
        if (!input) return 'no input';
        const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set;
        setter.call(input, 'https://app.example.test/signup');
        input.dispatchEvent(new Event('input', { bubbles: true }));
        return 'filled';
    }
    JS;

    $apply = <<<'JS'
    function () {
        const submit = [...document.querySelectorAll('[data-sp-links-panel] button')]
            .find((b) => b.className.includes('bg-accent-blue') && !b.className.includes('border-accent-blue'));
        if (!submit) return 'no submit';
        submit.click();
        return 'applied';
    }
    JS;

    visit("/apps/{$appId}/builder")->on()->macbookAir()
        // The header's controls are collapsed behind the options menu at every
        // width now, so fine-tune is one click deeper than it used to be.
        ->click(builderLabel('apps.builder.header_menu'))
        ->click(builderLabel('apps.builder.panel_mode_manual'))
        ->click(builderLabel('apps.builder.links_button'))
        ->assertScript($openEditor, 'opened')
        ->assertScript($fill, 'filled')
        ->assertScript($apply, 'applied')
        // One intention, one patch: all three anchors move together.
        ->assertSee('https://app.example.test/signup');

    $manifest = app(AppManifestService::class)->getActiveManifest(App::find($appId));
    $blocks = collect($manifest['pages'][0]['blocks'])->keyBy('id');

    expect($blocks['blk_header']['content'])->toContain('https://app.example.test/signup')
        // The link that pointed elsewhere is untouched.
        ->and($blocks['blk_header']['content'])->toContain('href="#pricing"')
        ->and($blocks['blk_pricing']['content'])->not->toContain('#waitlist')
        ->and(substr_count($blocks['blk_pricing']['content'], 'app.example.test/signup'))->toBe(2);
});
