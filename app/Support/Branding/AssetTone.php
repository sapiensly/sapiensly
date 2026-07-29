<?php

namespace App\Support\Branding;

/**
 * Whether a brand mark is drawn in light ink or dark ink — the question nobody
 * asks until the logo is invisible.
 *
 * Half the web puts its logo in a dark header, which means the logo published in
 * the markup is the light-ink one. Read it off the page and drop it into the
 * Brandbook's `logo_url` — the field light surfaces read — and it disappears.
 * {@see OrganizationBrand::logoFor()} makes that unrecoverable by design: dark
 * surfaces fall back to the base logo, light surfaces have nothing to fall back
 * to, so there is no arrangement of the two fields that saves a white logo on a
 * white header. The Brandbook already has the answer in `logo_bg_color`; what
 * was missing was noticing that it is needed.
 *
 * A signal, not a verdict. A two-tone mark, or one whose luminance sits in the
 * middle, is reported as {@see self::MIXED} rather than guessed at — this
 * proposes a backdrop to a human, it never silently restyles anybody's brand.
 */
final class AssetTone
{
    /** Light ink on transparency: needs a dark backdrop to be visible on light surfaces. */
    public const LIGHT = 'light';

    /** Dark ink on transparency: fine on the light surfaces the base logo is used on. */
    public const DARK = 'dark';

    /** The image carries its own background, so it reads on any surface. */
    public const OPAQUE = 'opaque';

    /** Neither clearly light nor clearly dark, or not measurable at all. */
    public const MIXED = 'mixed';

    /**
     * Above this share of opaque pixels the image is a filled rectangle rather
     * than a mark on transparency — it brings its own background with it.
     */
    private const OPAQUE_RATIO = 0.9;

    /** Below this share there is not enough ink to measure anything honestly. */
    private const MIN_INK_RATIO = 0.02;

    /** GD alpha runs 0 (opaque) to 127 (transparent); half-way is the cut. */
    private const ALPHA_OPAQUE = 64;

    private const LIGHT_ABOVE = 0.65;

    private const DARK_BELOW = 0.35;

    /** Cap on sampled pixels, so a 4000px logo costs the same as a 400px one. */
    private const MAX_SAMPLES = 10000;

    private function __construct(
        public readonly string $verdict,
        /** Mean luminance (0–1) of the ink, or null when nothing was measurable. */
        public readonly ?float $luminance,
    ) {}

    /** A mark drawn in light ink, which will vanish on a light surface. */
    public function isLight(): bool
    {
        return $this->verdict === self::LIGHT;
    }

    /**
     * Read the tone of an image. Anything unreadable — a format GD does not
     * decode (an .ico, notably), a truncated file — is {@see self::MIXED}, never
     * an exception: this informs a proposal, it does not gate one.
     */
    public static function of(string $bytes): self
    {
        // GD raises a warning for input it cannot decode, and undecodable input
        // is a NORMAL answer here — a favicon is very often an .ico, which GD
        // does not read. `@` alone would not do: PHPUnit's error handler ignores
        // it, so the noise would land in every test run that imports an icon.
        set_error_handler(static fn (): bool => true);

        try {
            $image = imagecreatefromstring($bytes);
        } finally {
            restore_error_handler();
        }

        if ($image === false) {
            return new self(self::MIXED, null);
        }

        try {
            if (! imageistruecolor($image)) {
                imagepalettetotruecolor($image);
            }

            $width = imagesx($image);
            $height = imagesy($image);
            if ($width < 1 || $height < 1) {
                return new self(self::MIXED, null);
            }

            $step = max(1, (int) ceil(sqrt(($width * $height) / self::MAX_SAMPLES)));

            $sampled = 0;
            $opaque = 0;
            $luminanceSum = 0.0;

            for ($y = 0; $y < $height; $y += $step) {
                for ($x = 0; $x < $width; $x += $step) {
                    $sampled++;
                    $rgba = imagecolorat($image, $x, $y);

                    if ((($rgba >> 24) & 0x7F) >= self::ALPHA_OPAQUE) {
                        continue;
                    }

                    $opaque++;
                    $luminanceSum += (
                        0.299 * (($rgba >> 16) & 0xFF)
                        + 0.587 * (($rgba >> 8) & 0xFF)
                        + 0.114 * ($rgba & 0xFF)
                    ) / 255;
                }
            }
        } finally {
            imagedestroy($image);
        }

        if ($sampled === 0 || $opaque / $sampled < self::MIN_INK_RATIO) {
            return new self(self::MIXED, null);
        }

        $luminance = $luminanceSum / $opaque;

        if ($opaque / $sampled > self::OPAQUE_RATIO) {
            return new self(self::OPAQUE, $luminance);
        }

        return new self(match (true) {
            $luminance > self::LIGHT_ABOVE => self::LIGHT,
            $luminance < self::DARK_BELOW => self::DARK,
            default => self::MIXED,
        }, $luminance);
    }
}
