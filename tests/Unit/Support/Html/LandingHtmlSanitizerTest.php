<?php

use App\Support\Html\LandingHtmlSanitizer;

function clean(string $html): string
{
    return (new LandingHtmlSanitizer)->sanitize($html);
}

it('keeps landing structure and the class/id hooks custom_css targets', function () {
    $out = clean('<section class="hero" id="top"><div class="hero-grid"><h1 class="title">Hola</h1><p>Sub</p></div></section>');

    expect($out)
        ->toContain('<section class="hero" id="top">')
        ->toContain('<div class="hero-grid">')
        ->toContain('<h1 class="title">Hola</h1>')
        ->toContain('<p>Sub</p>');
});

it('keeps every heading level', function () {
    $out = clean('<h1>a</h1><h2>b</h2><h3>c</h3><h4>d</h4><h5>e</h5><h6>f</h6>');
    foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $h) {
        expect($out)->toContain("<{$h}>");
    }
});

it('drops <script> whole, including its text', function () {
    $out = clean('<div>ok</div><script>alert(1)</script>');
    expect($out)->toContain('<div>ok</div>')
        ->not->toContain('alert')
        ->not->toContain('script');
});

it('drops <style> whole so no author CSS leaks in via markup', function () {
    $out = clean('<p>hi</p><style>.x{color:red}</style>');
    expect($out)->toContain('<p>hi</p>')
        ->not->toContain('color:red')
        ->not->toContain('style');
});

it('strips inline style attributes (styling belongs in custom_css)', function () {
    $out = clean('<div style="background:url(javascript:alert(1))">x</div>');
    expect($out)->toContain('<div>x</div>')
        ->not->toContain('style=')
        ->not->toContain('javascript');
});

it('strips every event handler', function () {
    $out = clean('<div onclick="a()"><span onmouseover="b()">x</span></div>');
    expect($out)->not->toContain('onclick')
        ->not->toContain('onmouseover')
        ->toContain('<div>')
        ->toContain('<span>x</span>');
});

it('drops iframe, object, form and svg outright', function () {
    $out = clean('<div>keep</div><iframe src="//evil"></iframe><object data="x"></object><form><input></form><svg onload="alert(1)"></svg>');
    expect($out)->toContain('<div>keep</div>')
        ->not->toContain('iframe')
        ->not->toContain('object')
        ->not->toContain('<form')
        ->not->toContain('<input')
        ->not->toContain('svg')
        ->not->toContain('onload');
});

it('keeps a safe link and hardens external ones', function () {
    $out = clean('<a href="https://example.com">out</a> <a href="#pricing">in</a>');
    expect($out)
        ->toContain('href="https://example.com"')
        ->toContain('target="_blank"')
        ->toContain('rel="noopener noreferrer nofollow"')
        ->toContain('href="#pricing"');
    // The internal anchor is NOT forced to a new tab.
    expect(substr_count($out, 'target="_blank"'))->toBe(1);
});

it('strips a javascript: href but keeps the element', function () {
    $out = clean('<a href="javascript:alert(1)">x</a>');
    expect($out)->toContain('>x</a>')
        ->not->toContain('javascript');
});

it('sanitises images: keeps safe src + alt, strips onerror and unsafe src', function () {
    $ok = clean('<img src="https://cdn.example.com/a.png" alt="hero" onerror="alert(1)">');
    expect($ok)->toContain('src="https://cdn.example.com/a.png"')
        ->toContain('alt="hero"')
        ->not->toContain('onerror');

    $bad = clean('<img src="javascript:alert(1)" alt="x">');
    expect($bad)->toContain('alt="x"')->not->toContain('javascript');
});

it('allows raster data-URI images but never data:image/svg or data:text', function () {
    expect(clean('<img src="data:image/png;base64,AAAA" alt="p">'))->toContain('data:image/png;base64,AAAA');
    expect(clean('<img src="data:image/svg+xml;base64,AAAA" alt="s">'))->not->toContain('svg+xml');
    expect(clean('<img src="data:text/html;base64,AAAA" alt="h">'))->not->toContain('data:text');
});

it('forces every button inert (type=button, no behaviour)', function () {
    $out = clean('<button type="submit" onclick="pay()" class="cta">Comprar</button>');
    expect($out)->toContain('type="button"')
        ->toContain('class="cta"')
        ->toContain('Comprar')
        ->not->toContain('submit')
        ->not->toContain('onclick');
});

it('keeps aria-* and data-sp-* hooks, drops other data-* and unknown attrs', function () {
    $out = clean('<div class="x" aria-label="menu" data-sp-motion="ambient-field" data-tracking="evil" foo="bar">x</div>');
    expect($out)->toContain('aria-label="menu"')
        ->toContain('data-sp-motion="ambient-field"')
        ->not->toContain('data-tracking')
        ->not->toContain('foo=');
});

it('unwraps a disallowed-but-harmless tag, keeping its text', function () {
    $out = clean('<center>Hola <b>mundo</b></center>');
    expect($out)->toContain('Hola')
        ->toContain('<b>mundo</b>')
        ->not->toContain('center');
});

it('drops comments and returns empty for empty input', function () {
    expect(clean('<p>a</p><!-- secret --><p>b</p>'))->not->toContain('secret');
    expect(clean('   '))->toBe('');
});

it('removes a script nested deep inside allowed structure', function () {
    $out = clean('<section><div><p>ok<script>steal()</script></p></div></section>');
    expect($out)->toContain('<p>ok</p>')
        ->not->toContain('steal')
        ->not->toContain('script');
});
