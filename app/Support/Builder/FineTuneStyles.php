<?php

namespace App\Support\Builder;

use InvalidArgumentException;

/**
 * The security + persistence layer for the landing fine-tune STYLE overrides.
 *
 * Manual per-element style tweaks are written as override rules keyed by a
 * `data-sp-edit-id` anchor into a FENCED, managed region at the end of
 * settings.custom_css — never into the author's (or the AI's) own rules. The
 * cascade favours the later region, so an override wins without touching base
 * CSS, and the AI keeps authoring by append exactly as before.
 *
 * Because a declaration value ends up inside the stylesheet, this is a trust
 * boundary: `sanitize()` accepts ONLY a whitelist of properties, each validated
 * to a strict value shape, so nothing can break out of the rule (no `{`, `}`,
 * `;`, `@`, `url(...)`, expressions). An invalid value throws.
 */
class FineTuneStyles
{
    public const REGION_START = '/* sp:fine-tune:start (managed — manual style overrides) */';

    public const REGION_END = '/* sp:fine-tune:end */';

    /** Anchor id shape — also the selector, so it must be injection-proof. */
    public const EDIT_ID_PATTERN = '/^spe_[a-z0-9]{4,32}$/';

    /**
     * The whitelist: client key → [css property, value validator]. A value that
     * fails its validator is rejected (the whole request 422s), so the region
     * only ever holds safe declarations.
     *
     * @var array<string, array{0: string, 1: callable(string): bool}>
     */
    private const PROPS = [
        'color' => ['color', [self::class, 'isHex']],
        'background' => ['background-color', [self::class, 'isHex']],
        'font_size' => ['font-size', [self::class, 'isFontSize']],
        'font_weight' => ['font-weight', [self::class, 'isWeight']],
        'text_align' => ['text-align', [self::class, 'isAlign']],
        'letter_spacing' => ['letter-spacing', [self::class, 'isTracking']],
        'line_height' => ['line-height', [self::class, 'isLineHeight']],
    ];

    /**
     * Validate + normalise incoming style keys into concrete CSS declarations.
     * A value of null/'' CLEARS that property (drops it from the rule).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string|null> css-property => value (null = clear)
     */
    public static function sanitize(array $input): array
    {
        $out = [];
        foreach ($input as $key => $value) {
            if (! isset(self::PROPS[$key])) {
                throw new InvalidArgumentException("Unsupported style property: {$key}");
            }
            [$cssProp, $validator] = self::PROPS[$key];

            if ($value === null || $value === '') {
                $out[$cssProp] = null; // clear

                continue;
            }
            $value = (string) $value;
            if (! $validator($value)) {
                throw new InvalidArgumentException("Invalid value for {$key}: {$value}");
            }
            $out[$cssProp] = $value;
        }

        return $out;
    }

    /**
     * Upsert one anchor's declarations into the managed region of a stylesheet:
     * merge the given declarations into any existing rule for $editId (a null
     * value removes that property; an empty resulting rule is dropped), rebuild
     * the region, and return the full stylesheet.
     *
     * @param  array<string, string|null>  $decls  css-property => value|null
     */
    public static function upsert(?string $css, string $editId, array $decls): string
    {
        if (preg_match(self::EDIT_ID_PATTERN, $editId) !== 1) {
            throw new InvalidArgumentException("Invalid edit id: {$editId}");
        }
        $css ??= '';

        [$base, $rules] = self::split($css);
        $current = $rules[$editId] ?? [];
        foreach ($decls as $prop => $value) {
            if ($value === null) {
                unset($current[$prop]);
            } else {
                $current[$prop] = $value;
            }
        }
        if ($current === []) {
            unset($rules[$editId]);
        } else {
            $rules[$editId] = $current;
        }

        return self::assemble($base, $rules);
    }

    /**
     * Split a stylesheet into [css-before-the-region, editId => declarations map].
     *
     * @return array{0: string, 1: array<string, array<string, string>>}
     */
    private static function split(string $css): array
    {
        $start = strpos($css, self::REGION_START);
        if ($start === false) {
            return [rtrim($css), []];
        }
        $base = rtrim(substr($css, 0, $start));
        $region = substr($css, $start);

        $rules = [];
        if (preg_match_all('/\[data-sp-edit-id="([^"\]]+)"\]\s*\{([^}]*)\}/', $region, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $decls = [];
                foreach (explode(';', $m[2]) as $pair) {
                    $pair = trim($pair);
                    if ($pair === '' || ! str_contains($pair, ':')) {
                        continue;
                    }
                    [$p, $v] = explode(':', $pair, 2);
                    $decls[trim($p)] = trim($v);
                }
                if ($decls !== []) {
                    $rules[$m[1]] = $decls;
                }
            }
        }

        return [$base, $rules];
    }

    /**
     * @param  array<string, array<string, string>>  $rules
     */
    private static function assemble(string $base, array $rules): string
    {
        if ($rules === []) {
            return $base;
        }
        $lines = [self::REGION_START];
        foreach ($rules as $editId => $decls) {
            $body = implode(';', array_map(fn ($p, $v) => "{$p}:{$v}", array_keys($decls), array_values($decls)));
            $lines[] = "[data-sp-edit-id=\"{$editId}\"]{{$body}}";
        }
        $lines[] = self::REGION_END;

        return ($base === '' ? '' : $base."\n\n").implode("\n", $lines);
    }

    public static function isHex(string $v): bool
    {
        return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $v) === 1;
    }

    public static function isFontSize(string $v): bool
    {
        if (preg_match('/^(\d+(?:\.\d+)?)(rem|px)$/', $v, $m) !== 1) {
            return false;
        }
        $n = (float) $m[1];

        return $m[2] === 'rem' ? ($n >= 0.5 && $n <= 8) : ($n >= 8 && $n <= 160);
    }

    public static function isWeight(string $v): bool
    {
        return preg_match('/^(100|200|300|400|500|600|700|800|900)$/', $v) === 1;
    }

    public static function isAlign(string $v): bool
    {
        return in_array($v, ['left', 'center', 'right', 'justify'], true);
    }

    public static function isTracking(string $v): bool
    {
        if (preg_match('/^(-?\d+(?:\.\d+)?)(em|px)$/', $v, $m) !== 1) {
            return false;
        }
        $n = (float) $m[1];

        return $m[2] === 'em' ? ($n >= -0.1 && $n <= 0.6) : ($n >= -4 && $n <= 16);
    }

    public static function isLineHeight(string $v): bool
    {
        return preg_match('/^\d+(?:\.\d+)?$/', $v) === 1 && (float) $v >= 0.8 && (float) $v <= 3;
    }
}
