<?php

use App\Support\Landing\BundledDesign;

/**
 * The shape a real Claude Design export ships: a loader whose body is a spinner,
 * and the actual document tucked inside a `__bundler/template` script as a
 * JSON-encoded string.
 */
function bundleFixture(?string $templateCss = null, ?string $loaderCss = null): string
{
    $templateCss ??= <<<'CSS'
        @font-face { font-family: 'Poppins'; src: url("09ac5f9c-94b8-4269-ab3a") format('woff2'); }
        @font-face { font-family: 'Montserrat'; font-style: italic; src: url("aa11bb22-cc33") format('woff2'); }
        :root { --sp-bg-primary: #00031C; --sp-accent-blue: #0096FF; }
        .hero { font-family: 'Poppins', sans-serif; }
        CSS;

    $loaderCss ??= '#__bundler_loading { position: fixed; bottom: 20px; } body { background: #00031C; }';

    $template = json_encode(
        '<!DOCTYPE html><html lang="en"><head><title>Sapiensly — AI agents that do the work.</title>'
        ."<style>{$templateCss}</style>"
        .'</head><body><div id="root"></div></body></html>'
    );

    return '<!doctype html><html><head><style>'.$loaderCss.'</style></head><body>'
        .'<div>This page requires JavaScript to display.</div>'
        .'<div id="__bundler_thumbnail"></div>'
        .'<script type="__bundler/manifest">{"uuid":{"mime":"text/jsx","compressed":true,"data":"x"}}</script>'
        .'<script type="__bundler/template">'.$template.'</script>'
        .'</body></html>';
}

it('recognises a self-extracting bundle', function () {
    expect(BundledDesign::isBundle(bundleFixture()))->toBeTrue();
});

it('does not mistake an ordinary page for a bundle', function () {
    expect(BundledDesign::isBundle('<!doctype html><html><body><h1>Hola</h1></body></html>'))->toBeFalse()
        ->and(BundledDesign::isBundle(null))->toBeFalse();
});

it('recovers the real document rather than the loader shell', function () {
    $unpacked = BundledDesign::unpack(bundleFixture());

    expect($unpacked['document'])->toContain('<div id="root">')
        ->and($unpacked['document'])->toContain('AI agents that do the work.')
        // The loader's own body must not survive into the recovered document.
        ->and($unpacked['document'])->not->toContain('requires JavaScript');
});

it('recovers the design system and drops the loader stylesheet', function () {
    $unpacked = BundledDesign::unpack(bundleFixture());

    expect($unpacked['stylesheet'])->toContain('--sp-bg-primary: #00031C')
        ->and($unpacked['stylesheet'])->toContain('--sp-accent-blue: #0096FF')
        // This is what the importer used to hand over as "the design".
        ->and($unpacked['stylesheet'])->not->toContain('__bundler_loading');
});

it('strips @font-face but keeps the family names', function () {
    $unpacked = BundledDesign::unpack(bundleFixture());

    // Every src points at a manifest UUID, so the rules are dead weight on the
    // published page — but the names let the landing reload the faces.
    expect($unpacked['stylesheet'])->not->toContain('@font-face')
        ->and($unpacked['stylesheet'])->not->toContain('09ac5f9c')
        ->and($unpacked['fonts'])->toBe(['Poppins', 'Montserrat'])
        // A family referenced by the surviving rules is still readable.
        ->and($unpacked['stylesheet'])->toContain("font-family: 'Poppins'");
});

it('returns null when the bundle carries no template', function () {
    $html = '<!doctype html><html><body>'
        .'<script type="__bundler/manifest">{}</script></body></html>';

    expect(BundledDesign::isBundle($html))->toBeTrue()
        ->and(BundledDesign::unpack($html))->toBeNull();
});

it('tolerates a raw (non-JSON) template payload', function () {
    $html = '<!doctype html><body><script type="__bundler/template">'
        .'<html><head><style>:root{--a:#fff}</style></head><body><div id="root"></div></body></html>'
        .'</script></body>';

    expect(BundledDesign::unpack($html)['stylesheet'])->toContain('--a:#fff');
});
