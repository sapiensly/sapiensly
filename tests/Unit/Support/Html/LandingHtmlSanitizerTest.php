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

it('keeps <details>/<summary> — the honest zero-JS accordion — with `open` intact', function () {
    // A decorative +/− that toggles nothing is a lying affordance (observed
    // live in FAQ sections); native disclosure is the sanctioned alternative.
    $out = clean('<details class="faq-item" open onclick="x()"><summary class="faq-q">¿Y si ya la vi?</summary><p>Pasa 1 de cada 10 veces.</p></details>');

    expect($out)
        ->toContain('<details class="faq-item" open')
        ->toContain('<summary class="faq-q">¿Y si ya la vi?</summary>')
        ->toContain('<p>Pasa 1 de cada 10 veces.</p>')
        ->not->toContain('onclick');
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

it('drops iframe, object and form outright', function () {
    $out = clean('<div>keep</div><iframe src="//evil"></iframe><object data="x"></object><form><input></form>');
    expect($out)->toContain('<div>keep</div>')
        ->not->toContain('iframe')
        ->not->toContain('object')
        ->not->toContain('<form')
        ->not->toContain('<input');
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

it('leaves same-site links alone — only a scheme means "external"', function () {
    // `?lang=es` is the language switch the multilingual runtime reads. Sent to
    // a new tab it stops being a switch, and `rel="nofollow"` would tell
    // crawlers not to follow the hreflang partner we advertise on every page.
    $out = clean('<a href="?lang=es">ES</a><a href="/pricing">P</a><a href="docs/start">D</a>');
    expect($out)
        ->toContain('href="?lang=es"')
        ->toContain('href="/pricing"')
        ->toContain('href="docs/start"')
        ->not->toContain('target="_blank"')
        ->not->toContain('nofollow');
});

it('still hardens a link whose colon is a real scheme, not a path', function () {
    $out = clean('<a href="mailto:a@b.com">m</a><a href="/a/b:c?q=x:y">rel</a>');
    // One target, and it belongs to the mailto — the colons in the second href
    // sit after the path started, so it is not a scheme.
    expect(substr_count($out, 'target="_blank"'))->toBe(1);
    expect($out)->toContain('href="/a/b:c?q=x:y"');
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

/*
 * Inline SVG — the only way an authored icon exists. It used to be banned
 * outright, which is why a design import came back with every icon slot empty.
 * The element is allowed; its dangerous CHILDREN and attributes are not.
 */

it('keeps an icon: the shape vocabulary survives intact', function () {
    $out = clean(
        '<span class="ico"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" '
        .'stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">'
        .'<path d="M3 17l5-5 4 4 8-9"></path><circle cx="12" cy="12" r="3"></circle></svg></span>'
    );

    expect($out)->toContain('<svg')
        ->toContain('viewbox="0 0 24 24"')
        ->toContain('<path d="M3 17l5-5 4 4 8-9"')
        ->toContain('<circle cx="12" cy="12" r="3"')
        ->toContain('stroke="currentColor"')
        ->toContain('stroke-linecap="round"')
        ->toContain('aria-hidden="true"');
});

it('keeps a gradient-filled logo, including its local paint reference', function () {
    $out = clean(
        '<svg viewBox="0 0 128 128"><defs><linearGradient id="g"><stop offset="0" stop-color="#0096FF">'
        .'</stop></linearGradient></defs><path fill="url(#g)" d="M10 10h20v20z"></path>'
        .'<rect fill="var(--t-accent-blue)" x="0" y="0" width="4" height="4"></rect></svg>'
    );

    expect($out)->toContain('lineargradient')
        ->toContain('stop-color="#0096FF"')
        ->toContain('fill="url(#g)"')
        ->toContain('fill="var(--t-accent-blue)"');
});

it('strips the scripting surface from inside an svg', function () {
    // Every one of these is why svg was banned in the first place.
    $out = clean(
        '<svg onload="alert(1)"><script>alert(2)</script><style>*{x:y}</style>'
        .'<foreignObject><p onclick="alert(3)">html</p></foreignObject>'
        .'<use href="//evil.example/x.svg#i"></use>'
        .'<a href="javascript:alert(4)"><path d="M0 0"></path></a>'
        .'<animate attributeName="fill" to="red"></animate>'
        .'<path d="M1 1" onmouseover="alert(5)"></path></svg>'
    );

    expect($out)->toContain('<svg')
        ->toContain('<path d="M1 1"')
        ->not->toContain('alert')
        ->not->toContain('onload')
        ->not->toContain('onmouseover')
        ->not->toContain('<script')
        ->not->toContain('<style')
        ->not->toContain('foreignobject')
        ->not->toContain('<use')
        ->not->toContain('href')
        ->not->toContain('<animate');
});

it('drops smuggled markup inside an svg whole, without unwrapping its text', function () {
    // Unwrapping is how a payload surfaces: keep the shape tree closed.
    $out = clean('<svg><path d="M0 0"></path><p>SMUGGLED</p><div><span>ALSO</span></div></svg>');

    expect($out)->toContain('<path d="M0 0"')
        ->not->toContain('SMUGGLED')
        ->not->toContain('ALSO');
});

it('refuses a paint that points off the document', function () {
    $out = clean('<svg><path fill="url(https://evil.example/x)" stroke="url( //evil )" d="M0 0"></path></svg>');

    expect($out)->toContain('<path')
        ->toContain('d="M0 0"')
        ->not->toContain('evil');
});

it('strips style and inline handlers on the svg element itself', function () {
    $out = clean('<svg style="position:fixed" onfocus="alert(1)" viewBox="0 0 8 8"><path d="M0 0"></path></svg>');

    expect($out)->toContain('viewbox="0 0 8 8"')
        ->not->toContain('style')
        ->not->toContain('onfocus');
});

it('strips comments inside an svg — the mXSS carrier', function () {
    $out = clean('<svg><!--<img src=x onerror=alert(1)>--><path d="M0 0"></path></svg>');

    expect($out)->not->toContain('onerror')
        ->not->toContain('<!--')
        ->toContain('<path');
});

it('still refuses a data:image/svg source on an img', function () {
    // Allowing authored <svg> must not re-open the data-URI vector.
    expect(clean('<img src="data:image/svg+xml;base64,AAAA" alt="s">'))->not->toContain('svg+xml');
});

/**
 * The invariant behind "write a block's content in ONE op": the sanitiser runs
 * on EVERY save and repairs the markup, so a partial chunk comes back with its
 * open ancestors closed. Append the next chunk and it lands OUTSIDE the element
 * it belonged to — silently, since the patch still succeeds. Cost a live
 * landing half its logo marquee, rendering as a stray row below the track.
 */
it('closes the open tags of a partial fragment, which is why content cannot be appended in chunks', function () {
    $partial = '<section class="band"><div class="track"><span>one</span>';

    $out = clean($partial);

    expect($out)->toEndWith('</span></div></section>');
});
