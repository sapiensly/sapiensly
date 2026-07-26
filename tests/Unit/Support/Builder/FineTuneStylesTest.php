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

it('preserve restores the region when an AI turn dropped it', function () {
    $prev = FineTuneStyles::upsert('.lp{color:#000}', 'spe_aa11', ['color' => '#ffffff']);
    // AI rewrote custom_css without the managed region.
    $out = FineTuneStyles::preserve($prev, '.lp{color:red}');

    expect($out)->toContain('.lp{color:red}')
        ->toContain('[data-sp-edit-id="spe_aa11"]{color:#ffffff}')
        ->and(rtrim($out))->toEndWith(FineTuneStyles::REGION_END); // kept last
});

it('preserve moves the region last when an AI appended rules after it', function () {
    $region = FineTuneStyles::upsert(null, 'spe_aa11', ['color' => '#ffffff']);
    $new = $region."\n.ai-added{color:blue}"; // AI appended AFTER the region
    $out = FineTuneStyles::preserve(null, $new);

    // The AI rule must come BEFORE the managed region so the override wins.
    expect(strpos($out, '.ai-added'))->toBeLessThan(strpos($out, FineTuneStyles::REGION_START))
        ->and(rtrim($out))->toEndWith(FineTuneStyles::REGION_END);
});

it("preserve keeps the new turn's region over the previous one", function () {
    $prev = FineTuneStyles::upsert(null, 'spe_aa11', ['color' => '#000000']);
    $new = FineTuneStyles::upsert('.x{}', 'spe_aa11', ['color' => '#ffffff']);
    $out = FineTuneStyles::preserve($prev, $new);

    expect($out)->toContain('[data-sp-edit-id="spe_aa11"]{color:#ffffff}')
        ->not->toContain('#000000');
});

it('preserve is a no-op when neither stylesheet has a region', function () {
    expect(FineTuneStyles::preserve('.a{}', '.b{color:red}'))->toBe('.b{color:red}');
});
