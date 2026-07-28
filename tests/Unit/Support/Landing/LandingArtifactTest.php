<?php

use App\Support\Landing\LandingArtifact;

it('treats a self-contained designed page as a landing', function () {
    $html = '<!doctype html><html><head><title>Sapiensly</title><style>'
        .str_repeat('.hero{display:grid;gap:2rem;color:#0059ff}', 40)
        .'</style></head><body><section class="hero"><h1>Agentes que ejecutan</h1></section>'
        .'<section class="pricing"><h2>Precios</h2></section><footer>© 2026</footer></body></html>';

    expect(LandingArtifact::isLandingHtml($html))->toBeTrue();
});

it('does not treat a pasted fragment as a landing', function () {
    // Same marketing vocabulary, but a snippet of a screen — not a page.
    $html = '<section class="hero"><h1>Pricing</h1><p>testimonial</p></section>';

    expect(LandingArtifact::isLandingHtml($html))->toBeFalse();
});

it('does not treat a data-grid mockup as a landing', function () {
    $html = '<!doctype html><html><head><style>'.str_repeat('.cell{padding:4px}', 60).'</style></head>'
        .'<body><div class="sidebar-nav">Menu</div><table><thead><tr><th>Cliente</th></tr></thead>'
        .'<tbody><tr><td>ACME</td></tr></tbody></table><div class="pagination">1 2 3</div></body></html>';

    expect(LandingArtifact::isLandingHtml($html))->toBeFalse();
});

it('keeps a marketing page that happens to contain a comparison table', function () {
    $html = '<!doctype html><html><head><meta property="og:title" content="Sapiensly"><style>'
        .str_repeat('.plan{border-radius:12px}', 40)
        .'</style></head><body><section class="hero"><h1>Compara planes</h1></section>'
        .'<table><tr><td>Pro</td></tr></table><footer>faq</footer></body></html>';

    expect(LandingArtifact::isLandingHtml($html))->toBeTrue();
});

it('does not treat an unstyled document with no marketing signal as a landing', function () {
    $html = '<!doctype html><html><body><div>Untitled</div></body></html>';

    expect(LandingArtifact::isLandingHtml($html))->toBeFalse();
});

it('ignores a boilerplate reset as evidence of design', function () {
    $html = '<!doctype html><html><head><style>*{margin:0;padding:0}</style></head>'
        .'<body><div>Untitled</div></body></html>';

    expect(LandingArtifact::isLandingHtml($html))->toBeFalse();
});

it('sums css across every style block', function () {
    $html = '<style>.a{color:red}</style><style>.b{color:blue}</style>';

    expect(LandingArtifact::embeddedCssLength($html))->toBe(strlen('.a{color:red}') + strlen('.b{color:blue}'));
});

it('handles empty input', function () {
    expect(LandingArtifact::isLandingHtml(null))->toBeFalse()
        ->and(LandingArtifact::isLandingHtml(''))->toBeFalse();
});
