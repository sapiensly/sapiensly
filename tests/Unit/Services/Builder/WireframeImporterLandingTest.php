<?php

use App\Services\Builder\WireframeImporter;
use Tests\TestCase;

// Boots the container (WireframeImporter needs the SSRF stack) without
// RefreshDatabase — nothing here touches the database.
uses(TestCase::class);

beforeEach(function () {
    $this->importer = app(WireframeImporter::class);
});

function designedPage(?string $css = null): string
{
    $css ??= str_repeat('.hero{display:grid;gap:2rem;color:#0059ff}', 40);

    return '<!doctype html><html><head><title>Sapiensly</title>'
        ."<style>{$css}</style>"
        .'</head><body><section class="hero"><h1>Agentes que ejecutan</h1></section>'
        .'<section class="pricing"><h2>Precios</h2></section><footer>faq</footer></body></html>';
}

it('hands the stylesheet over for a designed page', function () {
    $parsed = $this->importer->fromHtml(designedPage());

    expect($parsed['is_landing'])->toBeTrue()
        ->and($parsed['stylesheet'])->toContain('.hero')
        ->and($parsed['stylesheet'])->toContain('#0059ff');
});

it('still drops style from the structural excerpt', function () {
    // The CSS travels in its own field — leaving it inline too would pay for
    // the same tokens twice.
    $parsed = $this->importer->fromHtml(designedPage());

    expect($parsed['cleaned_html'])->not->toContain('<style')
        ->and($parsed['cleaned_html'])->toContain('class="hero"');
});

it('keeps the app-wireframe path free of stylesheet noise', function () {
    $html = '<!doctype html><html><head><style>'.str_repeat('.cell{padding:4px}', 60).'</style></head>'
        .'<body><div class="sidebar-nav">Menu</div><table><thead><tr><th>Cliente</th></tr></thead>'
        .'<tbody><tr><td>ACME</td></tr></tbody></table><div class="pagination">1 2</div></body></html>';

    $parsed = $this->importer->fromHtml($html);

    expect($parsed['is_landing'])->toBeFalse()
        ->and($parsed['stylesheet'])->toBeNull();
});

it('concatenates every style block and strips css comments', function () {
    $html = designedPage('/* palette */ .a{color:red}</style><style>.b{color:blue}');

    $parsed = $this->importer->fromHtml($html);

    expect($parsed['stylesheet'])->toContain('.a{color:red}')
        ->and($parsed['stylesheet'])->toContain('.b{color:blue}')
        ->and($parsed['stylesheet'])->not->toContain('palette');
});

it('caps the stylesheet at the landing custom_css budget', function () {
    $parsed = $this->importer->fromHtml(designedPage(str_repeat('.x{color:#0059ff}', 8000)));

    expect(strlen((string) $parsed['stylesheet']))->toBeLessThanOrEqual(60000 + strlen('…'));
});

it('gives a landing a larger structural budget than an app wireframe', function () {
    // 50 KB of markup: over the 30 KB app cap, under the 80 KB landing cap.
    $sections = str_repeat('<section class="hero"><h1>Precios y faq</h1><p>'.str_repeat('x', 200).'</p></section>', 200);
    $landing = '<!doctype html><html><head><title>L</title><style>'
        .str_repeat('.hero{gap:2rem}', 40).'</style></head><body>'.$sections.'</body></html>';

    $parsed = $this->importer->fromHtml($landing);

    expect($parsed['is_landing'])->toBeTrue()
        ->and(strlen((string) $parsed['cleaned_html']))->toBeGreaterThan(30001);
});

it('returns the new keys for a fragment paste too', function () {
    $parsed = $this->importer->fromHtml('<section class="hero"><h1>Hola</h1></section>');

    expect($parsed)->toHaveKeys(['stylesheet', 'is_landing'])
        ->and($parsed['is_landing'])->toBeFalse();
});
