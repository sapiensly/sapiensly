<?php

namespace App\Support\Branding;

/**
 * Derives a professional colour palette from a single accent hex — a tint/shade
 * ramp, a soft surface tint, a readable on-accent text colour, and a cohesive
 * categorical series for charts. Deterministic and restrained (no neon) so the
 * generated UIs stay attractive yet executive. This is the single source of the
 * palette: the runtime injects it as CSS variables and the builder's
 * generate_palette tool returns it verbatim.
 */
final class ColorPalette
{
    /** Tint/shade stops: positive = mix with white, negative = mix with black. */
    private const RAMP = [
        '50' => 0.90, '100' => 0.80, '200' => 0.62, '300' => 0.42, '400' => 0.20,
        '500' => 0.0, '600' => -0.16, '700' => -0.32, '800' => -0.48, '900' => -0.62,
    ];

    /** Hue offsets (degrees) for the categorical chart series — analogous + complement, kept cohesive. */
    private const CHART_HUES = [0, 32, -28, 160, 64, -64];

    /**
     * The base the `grays` mode derives EVERYTHING from — a slate deep enough to
     * stay a legitimate primary colour (buttons, the active pill) rather than
     * reading as disabled.
     */
    private const NEUTRAL_ACCENT = '#4b5563';

    /**
     * `grays` is a whole-surface mode, not just a chart-series mode: the ramp
     * feeds the hero gradient and the --sp-accent-* vars, so leaving it derived
     * from a blue accent left a "grayscale" board with a blue hero and a blue
     * active filter pill. Neutralise the SOURCE and every consumer follows.
     * `accent` is the colour the surface should actually paint with — callers
     * bind --sp-accent to it, not to the raw brand accent.
     *
     * @return array{accent: string, ramp: array<string, string>, soft: string, contrast: string, on_accent: string, chart: list<string>}
     */
    public static function fromAccent(string $accent, string $chartMode = 'brand'): array
    {
        if ($chartMode === 'grays') {
            $accent = self::NEUTRAL_ACCENT;
        }

        [$r, $g, $b] = self::toRgb($accent);

        $ramp = [];
        foreach (self::RAMP as $stop => $amount) {
            $ramp[$stop] = $amount >= 0
                ? self::mix([$r, $g, $b], [255, 255, 255], $amount)
                : self::mix([$r, $g, $b], [0, 0, 0], -$amount);
        }

        [$h, $s, $l] = self::rgbToHsl($r, $g, $b);
        $chart = match ($chartMode) {
            // Monochromatic: the accent at alternating depths — reads as ONE
            // brand voice; adjacency contrast comes from the lightness jumps.
            'accent' => array_map(
                fn (float $amount): string => $amount >= 0
                    ? self::mix([$r, $g, $b], [255, 255, 255], $amount)
                    : self::mix([$r, $g, $b], [0, 0, 0], -$amount),
                [0.0, 0.45, -0.30, 0.65, -0.50, 0.25],
            ),
            // Neutral grayscale — for boards where color must carry NO
            // categorical meaning at all.
            'grays' => ['#4b5563', '#9ca3af', '#1f2937', '#d1d5db', '#6b7280', '#374151'],
            default => (function () use ($h, $s, $l): array {
                $chart = [];
                foreach (self::CHART_HUES as $i => $offset) {
                    $hue = fmod(($h + $offset + 360), 360);
                    // Clamp saturation/lightness into a professional band so series stay
                    // harmonious regardless of the base accent, with a touch of variation.
                    $sat = max(0.42, min(0.68, $s));
                    $light = max(0.46, min(0.60, $l + ($i % 2 === 0 ? 0.0 : -0.06)));
                    $chart[] = self::hslToHex($hue, $sat, $light);
                }

                return $chart;
            })(),
        };

        return [
            'accent' => $accent,
            'ramp' => $ramp,
            'soft' => $ramp['50'],
            'contrast' => self::readableOn([$r, $g, $b]),
            'on_accent' => self::readableOn([$r, $g, $b]),
            'chart' => $chart,
        ];
    }

    /**
     * @param  list<int>  $hex
     * @param  list<int>  $towards
     */
    private static function mix(array $hex, array $towards, float $t): string
    {
        $out = [];
        for ($i = 0; $i < 3; $i++) {
            $out[$i] = (int) round($hex[$i] + ($towards[$i] - $hex[$i]) * $t);
        }

        return self::toHex($out);
    }

    /**
     * @param  list<int>  $rgb
     */
    private static function readableOn(array $rgb): string
    {
        $luminance = (0.299 * $rgb[0] + 0.587 * $rgb[1] + 0.114 * $rgb[2]) / 255;

        return $luminance > 0.6 ? '#0f172a' : '#ffffff';
    }

    /**
     * @return list<int>
     */
    private static function toRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (! preg_match('/^[0-9A-Fa-f]{6}$/', $hex)) {
            $hex = '0096ff'; // platform default accent
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * @param  list<int>  $rgb
     */
    private static function toHex(array $rgb): string
    {
        return '#'.implode('', array_map(
            fn (int $c) => str_pad(dechex(max(0, min(255, $c))), 2, '0', STR_PAD_LEFT),
            $rgb,
        ));
    }

    /**
     * @return array{0: float, 1: float, 2: float} [hue 0-360, sat 0-1, light 0-1]
     */
    private static function rgbToHsl(int $r, int $g, int $b): array
    {
        $r /= 255;
        $g /= 255;
        $b /= 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;

        if ($d == 0.0) {
            return [0.0, 0.0, $l];
        }

        $s = $d / (1 - abs(2 * $l - 1));
        $h = match ($max) {
            $r => fmod((($g - $b) / $d), 6),
            $g => (($b - $r) / $d) + 2,
            default => (($r - $g) / $d) + 4,
        } * 60;

        return [fmod($h + 360, 360), $s, $l];
    }

    private static function hslToHex(float $h, float $s, float $l): string
    {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;

        [$r, $g, $b] = match (true) {
            $h < 60 => [$c, $x, 0.0],
            $h < 120 => [$x, $c, 0.0],
            $h < 180 => [0.0, $c, $x],
            $h < 240 => [0.0, $x, $c],
            $h < 300 => [$x, 0.0, $c],
            default => [$c, 0.0, $x],
        };

        return self::toHex([
            (int) round(($r + $m) * 255),
            (int) round(($g + $m) * 255),
            (int) round(($b + $m) * 255),
        ]);
    }
}
