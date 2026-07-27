<?php

use App\Support\Branding\OrganizationBrand;

it('normalizes and drops invalid values from the stored array', function () {
    $brand = OrganizationBrand::fromArray([
        'logo_url' => '  https://cdn.example.com/logo.png  ',
        'icon_url' => '  https://cdn.example.com/icon.png  ',
        'accent_color' => '#1A2B3C',
        'font' => 'comic',          // not in the allowed set
        'theme' => 'dark',
    ]);

    expect($brand->logoUrl)->toBe('https://cdn.example.com/logo.png') // trimmed
        ->and($brand->iconUrl)->toBe('https://cdn.example.com/icon.png') // trimmed
        ->and($brand->accentColor)->toBe('#1A2B3C')
        ->and($brand->font)->toBeNull()              // invalid enum dropped
        ->and($brand->theme)->toBe('dark');
});

it('drops an invalid accent hex', function () {
    expect(OrganizationBrand::fromArray(['accent_color' => 'not-a-color'])->accentColor)->toBeNull();
});

it('falls back the accent to the platform default blue when unset', function () {
    expect(OrganizationBrand::fromArray(null)->effectiveAccent())->toBe(OrganizationBrand::DEFAULT_ACCENT)
        ->and(OrganizationBrand::fromArray(['accent_color' => '#FF0000'])->effectiveAccent())->toBe('#FF0000');
});

it('reports empty for a blank brand', function () {
    expect(OrganizationBrand::fromArray(null)->isEmpty())->toBeTrue()
        ->and(OrganizationBrand::fromArray(['accent_color' => '#000000'])->isEmpty())->toBeFalse();
});

it('fills app settings only where the app left a gap', function () {
    $brand = OrganizationBrand::fromArray([
        'accent_color' => '#FF0000',
        'font' => 'serif',
        'theme' => 'dark',
        'logo_url' => 'https://cdn/logo.png',
    ]);

    // App already chose an accent and a logo — those win; font/theme are filled.
    $settings = $brand->applyToAppSettings([
        'accent' => '#00FF00',
        'brand' => ['logo' => 'https://cdn/app-own.png'],
    ]);

    expect($settings['accent'])->toBe('#00FF00')             // app wins
        ->and($settings['font'])->toBe('serif')              // filled
        ->and($settings['theme'])->toBe('dark')              // filled
        ->and($settings['brand']['logo'])->toBe('https://cdn/app-own.png'); // app wins
});

it('fills app accent and logo when the app left them unset', function () {
    $brand = OrganizationBrand::fromArray(['accent_color' => '#FF0000', 'logo_url' => 'https://cdn/logo.png']);

    $settings = $brand->applyToAppSettings([]);

    expect($settings['accent'])->toBe('#FF0000')
        ->and($settings['brand']['logo'])->toBe('https://cdn/logo.png');
});

it('maps the logo bg colour to the app header background', function () {
    $brand = OrganizationBrand::fromArray(['logo_bg_color' => '#102030']);

    // Fills when unset.
    expect($brand->applyToAppSettings([])['brand']['header_bg'])->toBe('#102030');

    // App's own header_bg wins.
    $kept = $brand->applyToAppSettings(['brand' => ['header_bg' => '#ABCDEF']]);
    expect($kept['brand']['header_bg'])->toBe('#ABCDEF');
});

it('maps the brand accent to the chatbot primary_color, leaving bg/text alone', function () {
    $brand = OrganizationBrand::fromArray([
        'accent_color' => '#FF0000',
        'logo_url' => 'https://cdn/logo.png',
    ]);

    $defaults = ['primary_color' => '#3B82F6', 'background_color' => '#FFFFFF', 'logo_url' => null];

    $appearance = $brand->applyToChatbotAppearance(
        ['primary_color' => '#3B82F6', 'background_color' => '#FFFFFF', 'logo_url' => null],
        $defaults,
    );

    expect($appearance['primary_color'])->toBe('#FF0000')          // default → filled from accent
        ->and($appearance['background_color'])->toBe('#FFFFFF')    // brand doesn't touch bg
        ->and($appearance['logo_url'])->toBe('https://cdn/logo.png');
});

it('keeps a chatbot custom primary_color over the brand accent', function () {
    $brand = OrganizationBrand::fromArray(['accent_color' => '#FF0000']);
    $defaults = ['primary_color' => '#3B82F6'];

    $appearance = $brand->applyToChatbotAppearance(['primary_color' => '#0000FF'], $defaults);

    expect($appearance['primary_color'])->toBe('#0000FF'); // customized, kept
});

it('roundtrips the dark-surface logo/icon variants', function () {
    $brand = OrganizationBrand::fromArray([
        'logo_url' => 'https://cdn/logo.png',
        'logo_dark_url' => '  https://cdn/logo-dark.png  ',
        'icon_dark_url' => 'https://cdn/icon-dark.png',
    ]);

    expect($brand->logoDarkUrl)->toBe('https://cdn/logo-dark.png') // trimmed
        ->and($brand->iconDarkUrl)->toBe('https://cdn/icon-dark.png')
        ->and($brand->toArray())->toHaveKeys(['logo_dark_url', 'icon_dark_url'])
        ->and($brand->toArray()['logo_dark_url'])->toBe('https://cdn/logo-dark.png');
});

it('resolves logo/icon per theme with an asymmetric dark fallback', function () {
    // Both a light logo and a dark variant, only a light icon.
    $brand = OrganizationBrand::fromArray([
        'logo_url' => 'https://cdn/logo.png',
        'logo_dark_url' => 'https://cdn/logo-dark.png',
        'icon_url' => 'https://cdn/icon.png',
    ]);

    expect($brand->logoFor('dark'))->toBe('https://cdn/logo-dark.png')  // variant used
        ->and($brand->logoFor('light'))->toBe('https://cdn/logo.png')   // base on light
        ->and($brand->logoFor(null))->toBe('https://cdn/logo.png')      // unknown → base
        ->and($brand->iconFor('dark'))->toBe('https://cdn/icon.png')    // no dark variant → base
        ->and($brand->iconFor('light'))->toBe('https://cdn/icon.png');
});

it('picks the chatbot logo by the widget background darkness', function () {
    $brand = OrganizationBrand::fromArray([
        'logo_url' => 'https://cdn/logo.png',
        'logo_dark_url' => 'https://cdn/logo-dark.png',
    ]);
    $defaults = ['background_color' => '#FFFFFF', 'logo_url' => null];

    // Dark widget background → dark logo variant.
    $dark = $brand->applyToChatbotAppearance(
        ['background_color' => '#0B1220', 'logo_url' => null],
        $defaults,
    );
    expect($dark['logo_url'])->toBe('https://cdn/logo-dark.png');

    // Light widget background → base logo.
    $light = $brand->applyToChatbotAppearance(
        ['background_color' => '#FFFFFF', 'logo_url' => null],
        $defaults,
    );
    expect($light['logo_url'])->toBe('https://cdn/logo.png');
});

it('falls back to the base logo on a dark chatbot with no dark variant', function () {
    $brand = OrganizationBrand::fromArray(['logo_url' => 'https://cdn/logo.png']);

    $out = $brand->applyToChatbotAppearance(
        ['background_color' => '#0B1220', 'logo_url' => null],
        ['background_color' => '#FFFFFF', 'logo_url' => null],
    );

    expect($out['logo_url'])->toBe('https://cdn/logo.png');
});

it('flows both logo variants into app settings fill-the-gaps', function () {
    $brand = OrganizationBrand::fromArray([
        'logo_url' => 'https://cdn/logo.png',
        'logo_dark_url' => 'https://cdn/logo-dark.png',
        'icon_dark_url' => 'https://cdn/icon-dark.png',
    ]);

    // App set its own dark logo — that wins; the base + dark icon are filled.
    $settings = $brand->applyToAppSettings([
        'brand' => ['logo_dark' => 'https://cdn/app-dark.png'],
    ]);

    expect($settings['brand']['logo'])->toBe('https://cdn/logo.png')          // filled
        ->and($settings['brand']['logo_dark'])->toBe('https://cdn/app-dark.png') // app wins
        ->and($settings['brand']['icon_dark'])->toBe('https://cdn/icon-dark.png'); // filled
});
