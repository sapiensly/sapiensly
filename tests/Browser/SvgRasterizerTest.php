<?php

use App\Services\Branding\BrandAssetImportFailed;
use App\Services\Branding\SvgRasterizer;

/**
 * The renderer itself, against a real headless Chromium — the wiring around it
 * is covered deterministically in tests/Feature/Branding/BrandAssetImportTest.
 * It lives in the browser group because it needs a browser binary, the same
 * reason the visit() tests do.
 */
it('renders an svg to real png bytes', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="240" height="80">'
        .'<rect width="240" height="80" fill="#f97316"/>'
        .'<text x="16" y="50" font-size="32" fill="#ffffff">Aerobit</text>'
        .'</svg>';

    $png = app(SvgRasterizer::class)->toPng($svg);

    // The PNG signature, and enough bytes that something was actually painted.
    expect(substr($png, 0, 8))->toBe("\x89PNG\r\n\x1a\n")
        ->and(strlen($png))->toBeGreaterThan(500);
});

/** Garbage in is a refusal with a message, not a half-written asset. */
it('refuses something that is not an svg at all', function () {
    expect(fn () => app(SvgRasterizer::class)->toPng('this is not markup'))
        ->toThrow(BrandAssetImportFailed::class);
});
