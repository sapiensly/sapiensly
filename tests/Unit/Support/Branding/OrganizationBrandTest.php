<?php

use App\Support\Branding\OrganizationBrand;

it('normalizes and drops invalid values from the stored array', function () {
    $brand = OrganizationBrand::fromArray([
        'logo_url' => '  https://cdn.example.com/logo.png  ',
        'accent_color' => '#1A2B3C',
        'font' => 'comic',          // not in the allowed set
        'theme' => 'dark',
        'icon_emoji' => '🚀',
    ]);

    expect($brand->logoUrl)->toBe('https://cdn.example.com/logo.png') // trimmed
        ->and($brand->accentColor)->toBe('#1A2B3C')
        ->and($brand->font)->toBeNull()              // invalid enum dropped
        ->and($brand->theme)->toBe('dark')
        ->and($brand->iconEmoji)->toBe('🚀');
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
