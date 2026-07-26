<?php

use App\Support\Builder\FineTuneStyles;

it('sanitizes whitelisted properties into css declarations', function () {
    $decls = FineTuneStyles::sanitize([
        'color' => '#00d67f',
        'font_size' => '3.2rem',
        'font_weight' => '700',
        'text_align' => 'center',
    ]);

    expect($decls)->toBe([
        'color' => '#00d67f',
        'font-size' => '3.2rem',
        'font-weight' => '700',
        'text-align' => 'center',
    ]);
});

it('rejects an unknown property', function () {
    FineTuneStyles::sanitize(['position' => 'absolute']);
})->throws(InvalidArgumentException::class);

it('rejects a CSS-injection value (not a valid colour)', function () {
    FineTuneStyles::sanitize(['color' => 'red;}body{display:none}']);
})->throws(InvalidArgumentException::class);

it('rejects out-of-range and malformed values', function () {
    expect(fn () => FineTuneStyles::sanitize(['font_size' => '99rem']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => FineTuneStyles::sanitize(['font_weight' => '650']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => FineTuneStyles::sanitize(['text_align' => 'middle']))->toThrow(InvalidArgumentException::class);
});

it('upserts a rule into a fresh managed region, keeping base css untouched', function () {
    $base = '.lp{--brand:#00d67f}';
    $css = FineTuneStyles::upsert($base, 'spe_abc123', ['color' => '#ffffff', 'font-size' => '2rem']);

    expect($css)->toContain('.lp{--brand:#00d67f}')
        ->toContain(FineTuneStyles::REGION_START)
        ->toContain('[data-sp-edit-id="spe_abc123"]{color:#ffffff;font-size:2rem}')
        ->toContain(FineTuneStyles::REGION_END);
});

it('merges declarations for the same anchor and clears with null', function () {
    $css = FineTuneStyles::upsert(null, 'spe_abc123', ['color' => '#ffffff', 'font-size' => '2rem']);
    // Change colour, keep font-size.
    $css = FineTuneStyles::upsert($css, 'spe_abc123', ['color' => '#00d67f']);
    expect($css)->toContain('[data-sp-edit-id="spe_abc123"]{color:#00d67f;font-size:2rem}');

    // Clear font-size.
    $css = FineTuneStyles::upsert($css, 'spe_abc123', ['font-size' => null]);
    expect($css)->toContain('[data-sp-edit-id="spe_abc123"]{color:#00d67f}')
        ->not->toContain('font-size');
});

it('drops the whole region when the last rule is cleared', function () {
    $base = '.lp{color:#fff}';
    $css = FineTuneStyles::upsert($base, 'spe_abc123', ['color' => '#000000']);
    $css = FineTuneStyles::upsert($css, 'spe_abc123', ['color' => null]);

    expect($css)->toBe('.lp{color:#fff}')
        ->and($css)->not->toContain(FineTuneStyles::REGION_START);
});

it('rejects an edit id that is not a safe anchor', function () {
    FineTuneStyles::upsert(null, 'spe_abc"]{}evil', ['color' => '#fff']);
})->throws(InvalidArgumentException::class);
