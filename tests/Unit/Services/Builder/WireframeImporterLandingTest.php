<?php

use App\Services\Builder\ImportedPageRenderer;
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

/**
 * A self-extracting bundle, defined HERE rather than borrowed from
 * BundledDesignTest: a Pest helper only exists when its file is loaded, so a
 * cross-file dependency passes when the whole suite runs and fails the moment
 * anyone runs this directory alone.
 */
function importerBundleFixture(): string
{
    $templateCss = "@font-face{font-family:'Poppins';src:url(\"09ac5f9c\") format('woff2')}"
        ."@font-face{font-family:'Montserrat';src:url(\"aa11bb22\") format('woff2')}"
        .':root{--sp-bg-primary:#00031C;--sp-accent-blue:#0096FF}';

    $template = json_encode(
        '<!DOCTYPE html><html><head><title>Sapiensly — AI agents that do the work.</title>'
        ."<style>{$templateCss}</style></head><body><div id=\"root\"></div></body></html>"
    );

    return '<!doctype html><html><head><style>#__bundler_loading{position:fixed}</style></head><body>'
        .'<div>This page requires JavaScript to display.</div>'
        .'<script type="__bundler/manifest">{"u":{"mime":"text/jsx","compressed":true,"data":"x"}}</script>'
        .'<script type="__bundler/template">'.$template.'</script>'
        .'</body></html>';
}

/**
 * A self-extracting bundle carries no page: the loader shell parses as "This
 * page requires JavaScript to display". The importer must take its evidence
 * from the recovered template plus a headless render, never from the shell.
 *
 * The renderer is faked — a unit test must not launch Chrome — but the contract
 * it stands in for is exercised end-to-end against the real file elsewhere.
 */
function fakeRenderer(?array $result): void
{
    app()->bind(ImportedPageRenderer::class, fn () => new class($result) extends ImportedPageRenderer
    {
        public function __construct(private ?array $result) {}

        public function render(string $html): ?array
        {
            return $this->result;
        }
    });
}

it('takes a bundle design from the template, not the loader shell', function () {
    fakeRenderer(['html' => '<header class="x1">SAPIENSLY</header><section class="hero"><h1>AI agents</h1></section>', 'styles' => '.x1{color:#fff}', 'screenshot_path' => null]);

    $parsed = app(WireframeImporter::class)->fromHtml(importerBundleFixture());

    expect($parsed['is_landing'])->toBeTrue()
        ->and($parsed['title'])->toBe('Sapiensly — AI agents that do the work.')
        // The design system, not `#__bundler_loading`.
        ->and($parsed['stylesheet'])->toContain('--sp-bg-primary:#00031C')
        ->and($parsed['stylesheet'])->not->toContain('__bundler_loading')
        ->and($parsed['fonts'])->toBe(['Poppins', 'Montserrat'])
        // The markup comes from the render — the only place it exists.
        ->and($parsed['cleaned_html'])->toContain('AI agents')
        ->and($parsed['cleaned_html'])->not->toContain('requires JavaScript');
});

it('surfaces the render screenshot so the model can see the page', function () {
    fakeRenderer(['html' => '<h1>hola</h1>', 'styles' => null, 'screenshot_path' => '/tmp/shot.jpg']);

    expect(app(WireframeImporter::class)->fromHtml(importerBundleFixture())['screenshot_path'])
        ->toBe('/tmp/shot.jpg');
});

it('degrades to static extraction when the render fails', function () {
    fakeRenderer(null);

    $parsed = app(WireframeImporter::class)->fromHtml(importerBundleFixture());

    // No markup to be had, but the design system and SEO still came through —
    // a failed render must not cost what static extraction already recovered.
    expect($parsed['is_landing'])->toBeTrue()
        ->and($parsed['stylesheet'])->toContain('--sp-accent-blue')
        ->and($parsed['title'])->toBe('Sapiensly — AI agents that do the work.');
});

it('does not render a static page that parsed fine', function () {
    // A real designed page needs no browser; spending one would be pure latency.
    fakeRenderer(['html' => 'SHOULD NOT BE USED', 'styles' => null, 'screenshot_path' => null]);

    $parsed = app(WireframeImporter::class)->fromHtml(
        designedPage().str_repeat('<p>Texto real y suficiente para no parecer una SPA vacía.</p>', 20)
    );

    expect($parsed['cleaned_html'])->not->toContain('SHOULD NOT BE USED');
});

it('carries the hoisted element styles through to the caller', function () {
    // The renderer pulls `style=` attributes into deduplicated rules; without
    // forwarding them the model is back to re-deriving a stylesheet from
    // attributes the sanitiser strips anyway.
    fakeRenderer([
        'html' => '<section class="hero x1"><h1>AI agents</h1></section>',
        'styles' => ".x1{padding:6rem 0;background:#00031C}\n.x2{color:#8890A6}",
        'screenshot_path' => null,
    ]);

    $parsed = app(WireframeImporter::class)->fromHtml(importerBundleFixture());

    expect($parsed['element_styles'])->toContain('.x1{padding:6rem 0;background:#00031C}')
        ->and($parsed['element_styles'])->toContain('.x2{color:#8890A6}')
        // The design system stays its own field — vocabulary vs usage.
        ->and($parsed['stylesheet'])->toContain('--sp-bg-primary');
});

it('reports no element styles when the render produced none', function () {
    fakeRenderer(['html' => '<h1>hola</h1>', 'styles' => null, 'screenshot_path' => null]);

    expect(app(WireframeImporter::class)->fromHtml(importerBundleFixture())['element_styles'])
        ->toBeNull();
});
