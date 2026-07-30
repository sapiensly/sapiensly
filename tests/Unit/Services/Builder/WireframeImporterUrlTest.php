<?php

use App\Services\Builder\ImportedPageRenderer;
use App\Services\Builder\WireframeImporter;
use App\Services\Security\Ssrf\DnsResolver;
use App\Support\Builder\WireframeImportMode;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Importing a live URL, which for most of the modern web means importing a page
 * that does not exist yet when the HTML arrives.
 *
 * The bug these cover: a React/Vue site serves `<div id="root"></div>`, the
 * importer parsed exactly that, and the model — handed a mount point — answered
 * that it cannot browse the web and asked the user for a screenshot.
 */
uses(TestCase::class);

/** A single-page-app shell: no page in it, and a script that would build one. */
function spaShell(): string
{
    return '<!doctype html><html><head><title>Robok — AI Agency</title>'
        .'<link rel="stylesheet" href="/assets/index.css">'
        .'<script type="module" src="/assets/index.js"></script>'
        .'</head><body><div id="root"></div></body></html>';
}

/**
 * Fake the browser. A unit test must not launch Chrome; what it stands in for is
 * exercised against the real renderer in tests/Browser.
 */
function fakeUrlRenderer(?array $result, ?array &$seen = null): void
{
    app()->bind(ImportedPageRenderer::class, function () use ($result, &$seen) {
        return new class($result, $seen) extends ImportedPageRenderer
        {
            public function __construct(private ?array $result, private ?array &$seen) {}

            public function render(string $html): ?array
            {
                $this->seen = ['mode' => 'document'];

                return null;
            }

            public function renderUrl(string $url, array $resolvedIps = []): ?array
            {
                $this->seen = ['mode' => 'url', 'url' => $url, 'ips' => $resolvedIps];

                return $this->result;
            }
        };
    });
}

beforeEach(function () {
    // A public-looking host that resolves somewhere public, so the SSRF guard
    // clears it without this test depending on real DNS.
    app()->bind(DnsResolver::class, fn () => new class implements DnsResolver
    {
        public function resolve(string $host): array
        {
            return ['93.184.216.34'];
        }
    });

    Http::fake([
        'robok.example/*' => Http::response(spaShell(), 200, ['Content-Type' => 'text/html']),
    ]);
});

it('renders a client-rendered url instead of importing its mount point', function () {
    $seen = null;
    fakeUrlRenderer([
        'html' => '<header><nav>Home</nav></header><section class="hero"><h1>AI Agency</h1></section>',
        'styles' => null,
        'css' => '.hero{background:#0b0b0f;color:#fff}',
        'fonts' => ['Sora'],
        'screenshot_path' => null,
    ], $seen);

    $parsed = app(WireframeImporter::class)->fromUrl('https://robok.example/');

    expect($parsed['cleaned_html'])->toContain('AI Agency')
        ->and($parsed['cleaned_html'])->not->toContain('id="root"')
        // Rendered at its own address — a copy on our disk resolves none of its
        // assets — and pinned to the IP the guard cleared.
        ->and($seen['mode'])->toBe('url')
        ->and($seen['url'])->toBe('https://robok.example/')
        ->and($seen['ips'])->toBe(['93.184.216.34']);
});

/** The text dump was taken off the shell; once the page exists, re-take it. */
it('reports the words the page actually rendered', function () {
    fakeUrlRenderer([
        'html' => '<section><h1>Agentes que ejecutan</h1><p>Squad digital.</p></section>',
        'styles' => null,
        'css' => null,
        'fonts' => [],
        'screenshot_path' => null,
    ]);

    expect(app(WireframeImporter::class)->fromUrl('https://robok.example/')['text'])
        ->toContain('Agentes que ejecutan');
});

it('takes the design off the stylesheets the live page fetched', function () {
    // Static extraction can never see this: the CSS is in a separate file.
    fakeUrlRenderer([
        'html' => '<section class="hero"><h1>AI Agency</h1></section>',
        'styles' => '.x1{padding:6rem}',
        'css' => ':root{--brand:#5b5bd6}.hero{background:var(--brand)}',
        'fonts' => ['Sora', 'Inter'],
        'screenshot_path' => null,
    ]);

    $parsed = app(WireframeImporter::class)->fromUrl('https://robok.example/', WireframeImportMode::Replica);

    expect($parsed['stylesheet'])->toContain('--brand:#5b5bd6')
        ->and($parsed['element_styles'])->toContain('.x1{padding:6rem}')
        ->and($parsed['fonts'])->toBe(['Sora', 'Inter']);
});

it('promotes the render screenshot so the model can see the page', function () {
    fakeUrlRenderer([
        'html' => '<h1>hola</h1>',
        'styles' => null,
        'css' => null,
        'fonts' => [],
        'screenshot_path' => '/tmp/shot.jpg',
    ]);

    expect(app(WireframeImporter::class)->fromUrl('https://robok.example/')['screenshot_path'])
        ->toBe('/tmp/shot.jpg');
});

it('lets the user say it is a replica, whatever the artifact looks like', function () {
    // A rendered DOM with no marketing words and no embedded CSS: the detector
    // would call this an app mockup. The user said copy it.
    fakeUrlRenderer([
        'html' => '<div class="grid"><span>Uno</span><span>Dos</span></div>',
        'styles' => null,
        'css' => '.grid{display:grid}',
        'fonts' => [],
        'screenshot_path' => null,
    ]);

    $parsed = app(WireframeImporter::class)->fromUrl('https://robok.example/', WireframeImportMode::Replica);

    expect($parsed['is_landing'])->toBeTrue()
        ->and($parsed['stylesheet'])->toContain('.grid{display:grid}');
});

it('lets the user say it is only inspiration, whatever the artifact looks like', function () {
    fakeUrlRenderer([
        'html' => '<section class="hero"><h1>Precios</h1></section><section class="pricing">faq testimonial</section>',
        'styles' => null,
        'css' => str_repeat('.hero{display:grid;color:#0059ff}', 40),
        'fonts' => [],
        'screenshot_path' => null,
    ]);

    $parsed = app(WireframeImporter::class)->fromUrl('https://robok.example/', WireframeImportMode::Inspiration);

    // Every signal says designed page; the user asked for an app anyway, and the
    // design evidence stays out of the way of that brief.
    expect($parsed['is_landing'])->toBeFalse()
        ->and($parsed['stylesheet'])->toBeNull();
});

it('recognises a designed page once the browser has built it', function () {
    // On auto, the verdict has to wait for the render: nothing in the shell says
    // marketing page, and everything in the rendered DOM does.
    fakeUrlRenderer([
        'html' => '<section class="hero"><h1>Precios</h1></section><section class="pricing"><h2>faq</h2></section><footer>testimonial</footer>',
        'styles' => null,
        'css' => str_repeat('.hero{display:grid;gap:2rem;color:#0059ff}', 40),
        'fonts' => [],
        'screenshot_path' => null,
    ]);

    expect(app(WireframeImporter::class)->fromUrl('https://robok.example/')['is_landing'])->toBeTrue();
});

it('degrades to what static extraction recovered when the render fails', function () {
    fakeUrlRenderer(null);

    $parsed = app(WireframeImporter::class)->fromUrl('https://robok.example/');

    // Nothing to show for the page, but the import must not lose the SEO it
    // already had — the caller decides whether that is enough to go on.
    expect($parsed['title'])->toBe('Robok — AI Agency')
        ->and($parsed['screenshot_path'])->toBeNull();
});

/**
 * A server-rendered page parses fine, so nothing about it looks client-rendered
 * — and copying it still needs the browser: its stylesheet is a separate file
 * and its proof is a picture. Without this, a replica of a static site arrived
 * with markup and no design at all.
 */
it('renders a static page too when the user asked for a replica', function () {
    Http::fake([
        'static.example/*' => Http::response(
            '<!doctype html><html><head><title>Robok</title><link rel="stylesheet" href="/a.css"></head>'
            .'<body><section class="hero"><h1>Agentes</h1>'
            .str_repeat('<p>Texto de sobra para que el parseo estatico parezca suficiente.</p>', 20)
            .'</section></body></html>',
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    fakeUrlRenderer([
        'html' => '<section class="hero"><h1>Agentes</h1></section>',
        'styles' => null,
        'css' => '.hero{background:#0b0b0f}',
        'fonts' => ['Sora'],
        'screenshot_path' => '/tmp/shot.jpg',
    ]);

    $parsed = app(WireframeImporter::class)->fromUrl('https://static.example/', WireframeImportMode::Replica);

    expect($parsed['stylesheet'])->toContain('.hero{background:#0b0b0f}')
        ->and($parsed['screenshot_path'])->toBe('/tmp/shot.jpg');
});

/** Inferring an app from a page that parsed fine spends no browser on it. */
it('does not render a static page when only inspiration was asked for', function () {
    Http::fake([
        'static.example/*' => Http::response(
            '<!doctype html><html><head><title>Robok</title></head><body><table><tr><td>ACME</td></tr></table>'
            .str_repeat('<p>Texto de sobra para que el parseo estatico parezca suficiente.</p>', 20)
            .'</body></html>',
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    $seen = null;
    fakeUrlRenderer(['html' => 'SHOULD NOT BE USED', 'styles' => null, 'css' => null, 'fonts' => [], 'screenshot_path' => null], $seen);

    $parsed = app(WireframeImporter::class)->fromUrl('https://static.example/', WireframeImportMode::Inspiration);

    expect($seen)->toBeNull()
        ->and($parsed['cleaned_html'])->not->toContain('SHOULD NOT BE USED')
        ->and($parsed['cleaned_html'])->toContain('ACME');
});
