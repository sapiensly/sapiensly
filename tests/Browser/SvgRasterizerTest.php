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

/**
 * The regression this exists for: Browsershot's transparentBackground() only
 * reaches the PDF command, so on a screenshot it is silently a no-op — and the
 * logo came back on opaque white, which turns a white logo into a blank image
 * and makes every tone reading downstream say "carries its own background".
 */
it('keeps the background transparent', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="80">'
        .'<rect x="20" y="20" width="160" height="40" fill="#ffffff"/>'
        .'</svg>';

    $image = imagecreatefromstring(app(SvgRasterizer::class)->toPng($svg));
    $width = imagesx($image);
    $height = imagesy($image);

    $transparent = 0;
    $sampled = 0;
    for ($y = 0; $y < $height; $y += 5) {
        for ($x = 0; $x < $width; $x += 5) {
            $sampled++;
            if (((imagecolorat($image, $x, $y) >> 24) & 0x7F) >= 64) {
                $transparent++;
            }
        }
    }
    imagedestroy($image);

    // The mark covers most of this canvas, so the exact share does not matter —
    // that ANY of it is transparent is what an opaque backdrop would break.
    expect($transparent / $sampled)->toBeGreaterThan(0.1);
});

/** Garbage in is a refusal with a message, not a half-written asset. */
it('refuses something that is not an svg at all', function () {
    expect(fn () => app(SvgRasterizer::class)->toPng('this is not markup'))
        ->toThrow(BrandAssetImportFailed::class);
});
