<?php

use App\Support\Site\SiteProfile;

$page = <<<'HTML'
<!doctype html>
<html>
<head>
    <title>  Acme Logistics — cadena de frío  </title>
    <meta name="description" content="Transportamos carga refrigerada a nivel nacional.">
    <meta name="Theme-Color" content="#0F766E">
    <meta name="color-scheme" content="dark">
    <link rel="apple-touch-icon" href="/img/touch-icon.png">
    <link rel="icon" href="/favicon.ico">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Fraunces:400,700|Archivo">
    <style>body { font-family: "Comic Sans MS", cursive; }</style>
    <script>var tracking = "should not be read";</script>
</head>
<body>
    <img src="/img/acme-logo.svg" alt="Acme">
    <h1>Acme  Logistics</h1>
    <p>Movemos &amp; almacenamos alimentos congelados.</p>
</body>
</html>
HTML;

it('reads the prose and the brand signals from one page', function () use ($page) {
    $site = SiteProfile::parse($page, 'https://acme.example/es/');

    expect($site->title)->toBe('Acme Logistics — cadena de frío')
        ->and($site->description)->toBe('Transportamos carga refrigerada a nivel nacional.')
        // Relative hrefs resolve against the page, and apple-touch-icon wins on size.
        ->and($site->iconUrl)->toBe('https://acme.example/img/touch-icon.png')
        ->and($site->logoUrl)->toBe('https://acme.example/img/acme-logo.svg')
        // Case-insensitive meta name, shorthand hex normalized.
        ->and($site->themeColor)->toBe('#0f766e')
        ->and($site->colorScheme)->toBe('dark')
        // The webfont CDN names the family outright; the CSS stack is the fallback,
        // and its generic keyword ("cursive") is not a brand signal.
        ->and($site->fonts)->toBe(['Fraunces', 'Archivo', 'Comic Sans MS'])
        ->and($site->hasBrandSignals())->toBeTrue();
});

it('reduces a page to the words a reader would see', function () use ($page) {
    // Blocks are separated, entities decoded, script/style contents gone.
    expect($site = SiteProfile::parse($page, 'https://acme.example')->text)
        ->toContain('Acme Logistics Movemos & almacenamos alimentos congelados.')
        ->and($site)->not->toContain('should not be read')
        ->and($site)->not->toContain('Comic Sans');
});

it('never welds adjacent blocks into a word that does not exist', function () {
    expect(SiteProfile::visibleText('<h1>Acme  Logistics</h1><p>We move food.</p>'))
        ->toBe('Acme Logistics We move food.')
        ->and(SiteProfile::visibleText('<script>only()</script>'))->toBeNull()
        ->and(SiteProfile::visibleText(''))->toBeNull();
});

/**
 * Sites routinely hard-code `http` in an og:image they serve over TLS anyway
 * (yuhu.mx does). Left alone it is mixed content the browser refuses to render.
 */
it('upgrades a same-host http asset when the page itself is https', function () {
    $site = SiteProfile::parse(
        '<html><head><meta property="og:image" content="http://acme.example/logo.png">'
        .'<link rel="icon" href="http://cdn.other.example/favicon.ico"></head><body>Hi</body></html>',
        'https://acme.example',
    );

    expect($site->logoUrl)->toBe('https://acme.example/logo.png')
        // A third-party host we have no evidence about is left exactly as found.
        ->and($site->iconUrl)->toBe('http://cdn.other.example/favicon.ico');
});

it('prefers og:image over an img that calls itself a logo', function () {
    $site = SiteProfile::parse(
        '<html><head><meta property="og:image" content="https://cdn.acme.example/card.png"></head>'
        .'<body><img src="/logo.png" alt="logo"></body></html>',
        'https://acme.example',
    );

    expect($site->logoUrl)->toBe('https://cdn.acme.example/card.png');
});

it('drops signals it cannot use rather than passing on garbage', function () {
    $site = SiteProfile::parse(
        '<html><head><meta name="theme-color" content="rebeccapurple">'
        .'<meta name="color-scheme" content="light dark">'
        .'<link rel="icon" href="data:image/png;base64,AAAA">'
        .'<style>a { font-family: var(--brand-font); }</style></head><body>Hi</body></html>',
        'https://acme.example',
    );

    // Named colours, ambiguous schemes, data: URIs and CSS variables say nothing.
    expect($site->themeColor)->toBeNull()
        ->and($site->colorScheme)->toBeNull()
        ->and($site->iconUrl)->toBeNull()
        ->and($site->fonts)->toBe([])
        ->and($site->hasBrandSignals())->toBeFalse();
});

it('still yields the prose when the markup is too broken to parse', function () {
    $site = SiteProfile::parse('<p>Unclosed <b>markup that never ends', 'https://acme.example');

    expect($site->text)->toContain('Unclosed markup that never ends')
        ->and($site->url)->toBe('https://acme.example');
});

it('is empty when the page yields nothing at all', function () {
    expect(SiteProfile::parse('   ', 'https://acme.example')->isEmpty())->toBeTrue();
});
