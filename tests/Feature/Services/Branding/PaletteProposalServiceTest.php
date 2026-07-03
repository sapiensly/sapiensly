<?php

use App\Services\Branding\PaletteProposalService;
use App\Support\Branding\OrganizationBrand;

/**
 * The deterministic halves of the palette proposer: the curated fallback and
 * the normalization of a model reply. The AI call itself is mocked at the
 * controller layer (see Settings/OrganizationBrandTest).
 */
it('leads the curated fallback with the current accent when one is set', function () {
    $service = app(PaletteProposalService::class);

    $proposals = $service->fallbackProposals('#113355');

    expect($proposals)->toHaveCount(4)
        ->and($proposals[0]['accent'])->toBe('#113355');
});

it('omits the current-accent proposal when the brand is unset or default', function () {
    $service = app(PaletteProposalService::class);

    expect($service->fallbackProposals(null)[0]['accent'])->not->toBe(OrganizationBrand::DEFAULT_ACCENT)
        ->and($service->fallbackProposals(OrganizationBrand::DEFAULT_ACCENT))->toHaveCount(4);
});

it('normalizes a model reply: keeps valid proposals, drops junk, caps at four', function () {
    $normalized = PaletteProposalService::normalizeProposals([
        'proposals' => [
            ['name' => 'Indigo', 'accent' => '#4F46E5', 'rationale' => 'Solid.'],
            ['name' => '', 'accent' => '#123456', 'rationale' => 'No name.'],
            ['name' => 'Bad hex', 'accent' => 'blue', 'rationale' => 'Invalid.'],
            'not-an-array',
            ['name' => 'Teal', 'accent' => '#0f766e'],
            ['name' => 'Amber', 'accent' => '#b45309'],
            ['name' => 'Berry', 'accent' => '#be185d'],
            ['name' => 'Fifth', 'accent' => '#222222'],
        ],
    ]);

    expect($normalized)->toHaveCount(4)
        ->and($normalized[0]['accent'])->toBe('#4f46e5')  // lowercased
        ->and($normalized[1]['rationale'])->toBe('');     // missing rationale tolerated
});

it('returns nothing for an unparseable reply shape', function () {
    expect(PaletteProposalService::normalizeProposals(null))->toBe([])
        ->and(PaletteProposalService::normalizeProposals(['nope' => true]))->toBe([]);
});
