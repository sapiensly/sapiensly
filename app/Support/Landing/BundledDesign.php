<?php

namespace App\Support\Landing;

/**
 * Unpacks a self-extracting design bundle — the shape Claude Design (and the
 * same family of prototyping tools) calls a "standalone HTML export".
 *
 * The name is a trap: there is no page in the file. What ships is a loader whose
 * <body> is a spinner and a thumbnail, plus a manifest of gzip+base64 blobs that
 * an in-browser Babel compiles and React mounts at runtime. Handed to the normal
 * importer it yields the loader's own stylesheet and the words "This page
 * requires JavaScript to display" — which is exactly what a live import produced
 * before this existed.
 *
 * Two pieces are recoverable without executing anything:
 *
 *   - `__bundler/template` is the REAL document: the <head>, the SEO metadata and
 *     the design-system stylesheet (the colour/type tokens the components
 *     reference through var(--…)). Its <body> is an empty mount point, so this
 *     yields no markup — recovering that needs a headless render.
 *   - the @font-face rules name the families, though their src points at manifest
 *     UUIDs that mean nothing outside the bundle. The names are worth keeping
 *     (settings.fonts can reload them); the dead url() rules are not.
 */
final class BundledDesign
{
    /**
     * How much of the recovered design system we hand to the model. NOT the storage budget: that is
     * 200,000 now (ScopedAppCss::LANDING_MAX_LENGTH) and the two were only ever
     * equal by coincidence. This one is a PROMPT cost — every extra character
     * is re-sent each turn — so it is sized by what a real page actually
     * carries (measured: 14 KB of design system, 41 KB of element rules).
     */
    private const MAX_STYLESHEET = 60000;

    /**
     * True when this HTML is a self-extracting bundle rather than a page.
     */
    public static function isBundle(?string $html): bool
    {
        return $html !== null && preg_match('/<script[^>]+type="__bundler\/(manifest|template)"/i', $html) === 1;
    }

    /**
     * Recover what the bundle carries without running its JavaScript.
     *
     * @return array{document: string, stylesheet: ?string, fonts: array<int, string>}|null
     *                                                                                      Null when the bundle carries no readable template.
     */
    public static function unpack(string $html): ?array
    {
        $document = self::template($html);
        if ($document === null) {
            return null;
        }

        $css = self::styleBlocks($document);

        return [
            'document' => $document,
            'stylesheet' => self::designSystem($css),
            'fonts' => self::fontFamilies($css),
        ];
    }

    /**
     * The real document, out of the `__bundler/template` block. The payload is a
     * JSON-encoded string (the loader writes it into the DOM), but tolerate a raw
     * one so a format tweak degrades instead of breaking.
     */
    private static function template(string $html): ?string
    {
        if (preg_match('/<script[^>]+type="__bundler\/template"[^>]*>(.*?)<\/script>/is', $html, $m) !== 1) {
            return null;
        }

        $payload = trim($m[1]);
        if ($payload === '') {
            return null;
        }

        $decoded = json_decode($payload, true);

        $document = is_string($decoded) ? $decoded : $payload;

        return trim($document) === '' ? null : $document;
    }

    /**
     * Every <style> block in the recovered document, in order.
     */
    private static function styleBlocks(string $document): string
    {
        if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $document, $m) < 1) {
            return '';
        }

        return implode("\n", $m[1]);
    }

    /**
     * The stylesheet minus @font-face: those rules are ~40% of the bytes and every
     * src points at a manifest UUID, so keeping them would spend the budget on
     * url()s that resolve to nothing on the published page.
     */
    private static function designSystem(string $css): ?string
    {
        $css = preg_replace('/@font-face\s*\{[^}]*\}/is', '', $css) ?? $css;
        $css = preg_replace('!/\*.*?\*/!s', '', $css) ?? $css;
        $css = preg_replace('/\n\s*\n+/', "\n", $css) ?? $css;
        $css = trim($css);

        if ($css === '') {
            return null;
        }

        return strlen($css) > self::MAX_STYLESHEET
            ? substr($css, 0, self::MAX_STYLESHEET).'…'
            : $css;
    }

    /**
     * Family names declared by @font-face, deduplicated. The binaries can't come
     * with us, but the names let the landing reload them via settings.fonts.
     *
     * @return array<int, string>
     */
    private static function fontFamilies(string $css): array
    {
        if (preg_match_all('/@font-face\s*\{[^}]*font-family:\s*[\'"]?([^;\'"}]+)[\'"]?\s*;/is', $css, $m) < 1) {
            return [];
        }

        $families = array_map(fn (string $name): string => trim($name), $m[1]);

        return array_values(array_unique(array_filter($families)));
    }
}
