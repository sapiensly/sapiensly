<?php

use App\Services\Builder\ImportedPageRenderer;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * The URL branch of the page renderer, against a real page in a real browser.
 *
 * Everything else about a URL import is unit-tested with the renderer faked, so
 * this is the one place the claim "we can read a site that builds itself in the
 * browser" is actually put to a browser. The fixture is a miniature SPA: an empty
 * body, a script that fills it, a stylesheet in its own file and a relative image
 * — the four things static extraction cannot recover.
 *
 * It is served over HTTP rather than from disk on purpose: a file:// origin would
 * not exercise the code path (relative asset resolution, an external stylesheet's
 * cssRules) that the whole method exists for.
 */
function serveFixture(string $dir): array
{
    // A high, unlikely port. Not random: Math-free determinism keeps a failure
    // reproducible, and a collision surfaces as an immediate connection error.
    $port = 8931;

    $server = Process::timeout(120)->start("php -S 127.0.0.1:{$port} -t {$dir}");

    // The built-in server binds in a few ms; poll rather than sleep blindly.
    for ($i = 0; $i < 50; $i++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($socket !== false) {
            fclose($socket);
            break;
        }
        usleep(100_000);
    }

    return [$server, "http://127.0.0.1:{$port}/"];
}

beforeEach(function () {
    // The guard is what normally clears a URL before it is rendered; a loopback
    // address is exactly what it refuses, so this test turns it off the same way
    // a developer importing from their own dev server would.
    config(['security.ssrf.enabled' => false]);

    $this->fixtureDir = storage_path('app/tmp/renderer-fixture-'.strtolower((string) Str::ulid()));
    mkdir($this->fixtureDir, 0755, true);

    file_put_contents($this->fixtureDir.'/style.css', <<<'CSS'
        :root { --brand: #5b5bd6; }
        .hero { background: var(--brand); padding: 4rem; }
        .never-used-by-this-page { color: #ff0000; }
        CSS);

    // Nothing in the body: the page is built after load, like the React site
    // that started all this.
    file_put_contents($this->fixtureDir.'/index.html', <<<'HTML'
        <!doctype html>
        <html><head>
            <title>Robok — AI Agency</title>
            <link rel="stylesheet" href="style.css">
        </head><body>
            <div id="root"></div>
            <script>
                document.getElementById('root').innerHTML =
                    '<section class="hero"><h1>Agentes que ejecutan</h1>'
                    + '<img src="pic.png" alt="hero">'
                    + '<p>Un squad digital que resuelve tickets de soporte sin intervencion humana, '
                    + 'clasifica, busca en la documentacion y ejecuta acciones reales.</p></section>';
            </script>
        </body></html>
        HTML);

    file_put_contents($this->fixtureDir.'/pic.png', base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
    ));
});

afterEach(function () {
    foreach (glob($this->fixtureDir.'/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($this->fixtureDir);
});

it('reads a page that builds itself in the browser', function () {
    [$server, $url] = serveFixture($this->fixtureDir);

    try {
        $rendered = app(ImportedPageRenderer::class)->renderUrl($url);
    } finally {
        $server->stop();
    }

    expect($rendered)->not->toBeNull();

    // The markup the script built — the thing a static fetch never sees.
    expect($rendered['html'])->toContain('Agentes que ejecutan')
        ->and($rendered['html'])->toContain('class="hero"');

    // The design, out of a stylesheet in its own file, narrowed to what the page
    // actually wears: a rule matching nothing is a framework's dead weight.
    expect($rendered['css'])->toContain('--brand: #5b5bd6')
        ->and($rendered['css'])->toContain('.hero')
        ->and($rendered['css'])->not->toContain('never-used-by-this-page');

    // Assets resolved against the page's own address: relative paths mean
    // nothing once the markup is served from somewhere else.
    expect($rendered['html'])->toContain($url.'pic.png');

    // And a picture of it, which for this page is the only visual evidence there is.
    expect($rendered['screenshot_path'])->not->toBeNull()
        ->and(filesize($rendered['screenshot_path']))->toBeGreaterThan(1000);

    app(ImportedPageRenderer::class)->cleanup($rendered['screenshot_path']);
});
