<?php

use App\Support\Branding\ColorPalette;

it('derives a full professional palette from an accent', function () {
    $p = ColorPalette::fromAccent('#0096ff');

    expect($p['ramp'])->toHaveKeys(['50', '100', '200', '300', '400', '500', '600', '700', '800', '900'])
        ->and($p['ramp']['500'])->toBe('#0096ff')          // 500 is the base
        ->and($p['soft'])->toBe($p['ramp']['50'])          // soft = lightest tint
        ->and($p['chart'])->toHaveCount(6);

    // Every value is a valid #RRGGBB.
    foreach ([...array_values($p['ramp']), $p['soft'], $p['contrast'], ...$p['chart']] as $hex) {
        expect($hex)->toMatch('/^#[0-9a-f]{6}$/');
    }
});

it('ramps from light tints to dark shades', function () {
    $p = ColorPalette::fromAccent('#0096ff');

    $lum = function (string $hex): float {
        $hex = ltrim($hex, '#');

        return 0.299 * hexdec(substr($hex, 0, 2))
            + 0.587 * hexdec(substr($hex, 2, 2))
            + 0.114 * hexdec(substr($hex, 4, 2));
    };

    // 50 (tint) is much lighter than 900 (shade).
    expect($lum($p['ramp']['50']))->toBeGreaterThan($lum($p['ramp']['900']));
});

it('picks a readable contrast colour for the accent', function () {
    expect(ColorPalette::fromAccent('#0b1220')['contrast'])->toBe('#ffffff') // dark accent → light text
        ->and(ColorPalette::fromAccent('#fde047')['contrast'])->toBe('#0f172a'); // light accent → dark text
});

it('falls back to the platform accent for a bad hex', function () {
    expect(ColorPalette::fromAccent('nope')['ramp']['500'])->toBe('#0096ff');
});
