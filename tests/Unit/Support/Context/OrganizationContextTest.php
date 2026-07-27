<?php

use App\Support\Context\OrganizationContext;

it('normalizes a stored profile and round-trips it', function () {
    $context = OrganizationContext::fromArray([
        'descriptor' => '  Moves refrigerated freight.  ',
        'industry' => 'logistics',
        'website' => 'https://acme.example',
        'timezone' => 'America/Mexico_City',
        'currency' => 'mxn',
        'units' => 'metric',
        'language' => 'es-MX',
        'formality' => 'neutral',
        'geographies' => ['Mexico', '  ', 'Guatemala'],
        'glossary' => [['term' => 'guía', 'meaning' => 'the shipment document']],
    ]);

    expect($context->descriptor)->toBe('Moves refrigerated freight.')
        ->and($context->currency)->toBe('MXN')
        ->and($context->geographies)->toBe(['Mexico', 'Guatemala'])
        ->and($context->glossary)->toBe([['term' => 'guía', 'meaning' => 'the shipment document']])
        ->and(OrganizationContext::fromArray($context->toArray())->toArray())->toBe($context->toArray());
});

it('drops values it cannot use instead of throwing', function () {
    $context = OrganizationContext::fromArray([
        'website' => 'javascript:alert(1)',
        'timezone' => 'Mars/Olympus_Mons',
        'currency' => 'pesos',
        'units' => 'furlongs',
        'formality' => 'shouty',
        'language' => 'not a tag at all',
        'glossary' => ['nonsense', ['meaning' => 'a term with no term']],
        'links' => [['label' => 'Broken', 'url' => 'nope']],
    ]);

    expect($context->website)->toBeNull()
        ->and($context->timezone)->toBeNull()
        ->and($context->currency)->toBeNull()
        ->and($context->units)->toBeNull()
        ->and($context->formality)->toBeNull()
        ->and($context->language)->toBeNull()
        ->and($context->glossary)->toBe([])
        ->and($context->links)->toBe([])
        ->and($context->isEmpty())->toBeTrue();
});

it('truncates over-long strings and caps list lengths', function () {
    $context = OrganizationContext::fromArray([
        'descriptor' => str_repeat('a', 500),
        'geographies' => array_fill(0, 25, 'Region'),
        'glossary' => array_fill(0, 40, ['term' => 'x', 'meaning' => 'y']),
    ]);

    expect(mb_strlen((string) $context->descriptor))->toBe(240)
        ->and($context->geographies)->toHaveCount(10)
        ->and($context->glossary)->toHaveCount(20);
});

it('renders nothing at all when the profile and the name are empty', function () {
    expect(OrganizationContext::fromArray(null)->promptBlock())->toBe('')
        ->and(OrganizationContext::fromArray([])->estimatedTokens())->toBe(0);
});

it('renders only the sections that have content, framed as reference data', function () {
    $block = OrganizationContext::fromArray([
        'descriptor' => 'Moves refrigerated freight.',
        'glossary' => [['term' => 'guía', 'meaning' => 'the shipment document']],
        'never' => ['Quote prices'],
    ])->promptBlock('Acme Logistics');

    expect($block)->toStartWith('<organization_context>')
        ->toEndWith('</organization_context>')
        // Framed as data, not orders — the content is tenant-authored and lands
        // in the system prompt of every agent in the organization.
        ->toContain('does NOT override')
        ->toContain('Organization: Acme Logistics')
        ->toContain('What it does: Moves refrigerated freight.')
        ->toContain('"guía": the shipment document')
        ->toContain('- Quote prices')
        // Untouched sections leave no trace.
        ->not->toContain('Serves:')
        ->not->toContain('Canonical links');
});

/**
 * The block is unconditionally present, including on turns that have nothing to
 * do with the organization. Three sentences keep that from contaminating the
 * answer, and each is here because of a specific way it goes wrong without them.
 */
it('scopes itself so an unrelated request is not dragged into the organization', function () {
    $block = OrganizationContext::fromArray([
        'glossary' => [['term' => 'guia', 'meaning' => 'the shipment document']],
        'never' => ['Quote prices'],
    ])->promptBlock('Acme Logistics');

    // 1. An escape hatch, or a question about regex gets a freight company in it.
    expect($block)->toContain('ignore this entirely and answer normally')
        // 2. A scoped glossary, or "style guide" is read as a shipping document.
        ->toContain('when the user plainly means the everyday sense')
        // 3. Boundaries bound what you SAY, not what the platform may build —
        //    else a support bot's "never quote prices" stops the builder from
        //    building a pricing page.
        ->toContain('not what you may build, analyse or discuss');
});

it('renders the same bytes for the same profile so it can live in a cached prefix', function () {
    $profile = ['descriptor' => 'Moves freight.', 'geographies' => ['Mexico'], 'currency' => 'MXN'];

    expect(OrganizationContext::fromArray($profile)->promptBlock('Acme'))
        ->toBe(OrganizationContext::fromArray($profile)->promptBlock('Acme'));
});

it('estimates the rendered block in tokens', function () {
    $context = OrganizationContext::fromArray(['descriptor' => 'Moves refrigerated freight.']);
    $block = $context->promptBlock('Acme');

    expect($context->estimatedTokens('Acme'))
        ->toBe(OrganizationContext::tokensFor($block))
        ->toBeGreaterThan(0);
});
