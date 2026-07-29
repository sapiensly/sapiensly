<?php

use App\Support\Branding\AssetTone;
use App\Support\Branding\ColorPalette;

/**
 * Reading whether a mark is drawn in light or dark ink. It exists because half
 * the web draws its logo for a dark header, and `logo_url` — the field light
 * surfaces read — has no fallback: a white logo dropped in there is a blank
 * header, and nothing downstream can work that out from a URL.
 */
function toneOf(callable $paint, int $width = 120, int $height = 60): AssetTone
{
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));

    $paint($image, $width, $height);

    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return AssetTone::of($bytes);
}

/** A white wordmark on transparency: the case the whole feature exists for. */
it('reads a light mark on transparency as light', function () {
    $tone = toneOf(function ($image) {
        $white = imagecolorallocatealpha($image, 255, 255, 255, 0);
        imagefilledrectangle($image, 10, 20, 110, 40, $white);
    });

    expect($tone->verdict)->toBe(AssetTone::LIGHT)
        ->and($tone->isLight())->toBeTrue();
});

it('reads a dark mark on transparency as dark', function () {
    $tone = toneOf(function ($image) {
        $ink = imagecolorallocatealpha($image, 20, 24, 32, 0);
        imagefilledrectangle($image, 10, 20, 110, 40, $ink);
    });

    expect($tone->verdict)->toBe(AssetTone::DARK)
        ->and($tone->isLight())->toBeFalse();
});

/**
 * A logo that brings its own background reads on any surface, so it needs no
 * backdrop — and its mean luminance is the background's, not the mark's, which
 * is exactly why it must not be judged as light or dark.
 */
it('recognizes a mark that carries its own background', function () {
    $tone = toneOf(function ($image, $w, $h) {
        $navy = imagecolorallocatealpha($image, 12, 20, 40, 0);
        imagefilledrectangle($image, 0, 0, $w, $h, $navy);
        $white = imagecolorallocatealpha($image, 255, 255, 255, 0);
        imagefilledrectangle($image, 10, 20, 110, 40, $white);
    });

    expect($tone->verdict)->toBe(AssetTone::OPAQUE)
        ->and($tone->isLight())->toBeFalse();
});

/** Mid-grey is neither, and guessing at it would be worse than saying so. */
it('refuses to call a mid-tone mark either way', function () {
    $tone = toneOf(function ($image) {
        $grey = imagecolorallocatealpha($image, 128, 128, 128, 0);
        imagefilledrectangle($image, 10, 20, 110, 40, $grey);
    });

    expect($tone->verdict)->toBe(AssetTone::MIXED);
});

/** Unreadable input informs nothing; it must never throw or claim a verdict. */
it('says mixed for anything it cannot decode', function (string $bytes) {
    $tone = AssetTone::of($bytes);

    expect($tone->verdict)->toBe(AssetTone::MIXED)
        ->and($tone->luminance)->toBeNull();
})->with([
    'not an image' => ['just some bytes'],
    'empty' => [''],
    // GD does not decode .ico, and a favicon very often is one.
    'an ico' => ["\x00\x00\x01\x00\x01\x00\x10\x10"],
]);

/**
 * The backdrop is drawn from the brand's own accent so the header looks chosen,
 * but never at the cost of being unreadable.
 */
it('derives a backdrop dark enough to carry white ink', function (string $accent, string $expected) {
    expect(ColorPalette::backdrop($accent))->toBe($expected);
})->with([
    'orange' => ['#f97316', '#5f2c08'],
    'blue' => ['#0096ff', '#003961'],
    // Even a yellow goes deep enough to stay on-brand.
    'yellow' => ['#fde047', '#60551b'],
    // A near-white accent is the exception: its deepest shade is still too
    // bright, and the neutral wins over an on-brand colour nobody can read a
    // white logo on.
    'near-white' => ['#fafafa', '#0f172a'],
]);
