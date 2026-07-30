<?php

namespace App\Services\Import;

use DateTimeImmutable;

/**
 * How a spreadsheet cell becomes a typed value. Shared by the profiler (which
 * decides a column's type by asking "would every value convert?") and the
 * importer (which then converts them) — one set of rules, so a column can never
 * be typed as a number by one and rejected as text by the other.
 */
final class ValueCoercion
{
    private const TRUE_WORDS = ['true', 'yes', 'y', 'si', 'sí', 'verdadero', 'v', 'x', 'on', 'activo'];

    private const FALSE_WORDS = ['false', 'no', 'n', 'falso', 'f', 'off', 'inactivo'];

    /** Separators a single cell may use to hold several values. */
    public const LIST_SEPARATORS = [',', ';', '|', '/'];

    /**
     * Parse a number written in any of the conventions a spreadsheet exports.
     *
     * The hard case is that "1.234" is one thousand two hundred thirty-four in
     * Spain and one-point-two-three-four in the US. When BOTH separators are
     * present the last one is the decimal point — that is unambiguous. When only
     * one is present, it is a decimal point only if it splits off one or two
     * trailing digits AND appears once; otherwise it groups thousands.
     */
    public static function toNumber(?string $raw): ?float
    {
        if ($raw === null) {
            return null;
        }

        // Drop currency symbols, spaces (including the non-breaking space Excel
        // uses as a thousands separator) and a trailing percent.
        $value = preg_replace('/[^\d,.\-+eE]/u', '', str_replace(["\u{00A0}", "\u{202F}"], '', $raw)) ?? '';
        if ($value === '' || $value === '-' || $value === '+') {
            return null;
        }

        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');

        if ($lastComma !== false && $lastDot !== false) {
            // Both present: the rightmost is the decimal separator.
            $decimal = $lastComma > $lastDot ? ',' : '.';
            $grouping = $decimal === ',' ? '.' : ',';
            $value = str_replace($grouping, '', $value);
            $value = str_replace($decimal, '.', $value);
        } elseif ($lastComma !== false) {
            $value = self::resolveSingleSeparator($value, ',');
        } elseif ($lastDot !== false) {
            $value = self::resolveSingleSeparator($value, '.');
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * One separator, appearing once, with 1-2 digits after it → a decimal
     * point ("12,5"). Anything else groups thousands ("1.234", "1,234,567").
     */
    private static function resolveSingleSeparator(string $value, string $separator): string
    {
        $parts = explode($separator, $value);
        $decimals = strlen(end($parts));

        return (count($parts) === 2 && $decimals >= 1 && $decimals <= 2)
            ? str_replace($separator, '.', $value)
            : str_replace($separator, '', $value);
    }

    public static function toBoolean(?string $raw): ?bool
    {
        if ($raw === null) {
            return null;
        }
        $value = mb_strtolower(trim($raw));

        if (in_array($value, self::TRUE_WORDS, true)) {
            return true;
        }
        if (in_array($value, self::FALSE_WORDS, true)) {
            return false;
        }

        return null;
    }

    /**
     * Parse a date. `$dayFirst` decides 03/04/2026 — the profiler works it out
     * from the whole column (a value with a first part above 12 settles it) and
     * the plan says which reading it chose, because the file itself cannot.
     *
     * Returns ISO — 'Y-m-d', or 'Y-m-d\TH:i:s\Z' when a time is present and
     * asked for — which is exactly what RecordWriteService stores.
     */
    public static function toDate(?string $raw, bool $dayFirst = true, bool $withTime = false): ?string
    {
        if ($raw === null) {
            return null;
        }
        $value = trim($raw);

        // ISO first: unambiguous, and what the spreadsheet reader already
        // produced for real date cells.
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})([ T](\d{2}):(\d{2})(:(\d{2}))?)?/', $value, $m) === 1) {
            return self::assemble((int) $m[1], (int) $m[2], (int) $m[3], (int) ($m[5] ?? 0), (int) ($m[6] ?? 0), (int) ($m[8] ?? 0), $withTime);
        }

        // d/m/Y, m/d/Y, d-m-Y, d.m.Y — with 2- or 4-digit years.
        if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2,4})([ T](\d{1,2}):(\d{2})(:(\d{2}))?)?#', $value, $m) === 1) {
            $a = (int) $m[1];
            $b = (int) $m[2];
            $year = (int) $m[3];
            if ($year < 100) {
                $year += $year < 70 ? 2000 : 1900;
            }

            // Evidence in the value itself always beats the column's guess.
            $day = $dayFirst ? $a : $b;
            $month = $dayFirst ? $b : $a;
            if ($a > 12 && $b <= 12) {
                [$day, $month] = [$a, $b];
            } elseif ($b > 12 && $a <= 12) {
                [$day, $month] = [$b, $a];
            }

            if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
                return null;
            }

            return self::assemble($year, $month, $day, (int) ($m[5] ?? 0), (int) ($m[6] ?? 0), (int) ($m[8] ?? 0), $withTime);
        }

        // Anything else (textual months, RFC formats) — let PHP try, but only
        // when it produces a real date rather than a relative interpretation.
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return $withTime ? gmdate('Y-m-d\TH:i:s\Z', $timestamp) : gmdate('Y-m-d', $timestamp);
    }

    private static function assemble(int $y, int $m, int $d, int $h, int $i, int $s, bool $withTime): ?string
    {
        if (! checkdate($m, $d, $y)) {
            return null;
        }

        $date = (new DateTimeImmutable)->setDate($y, $m, $d)->setTime($h, $i, $s);

        return $withTime ? $date->format('Y-m-d\TH:i:s\Z') : $date->format('Y-m-d');
    }

    /**
     * Split a cell holding several values ("rojo, azul"). Returns null when no
     * separator applies, so a caller can tell "one value" from "an empty list".
     *
     * @return list<string>|null
     */
    public static function splitList(?string $raw, string $separator): ?array
    {
        if ($raw === null) {
            return null;
        }

        $parts = array_values(array_filter(
            array_map('trim', explode($separator, $raw)),
            fn (string $p): bool => $p !== '',
        ));

        return $parts === [] ? null : $parts;
    }
}
