<?php

namespace App\Support\Css;

/**
 * Compiles an app's author-written `settings.custom_css` into a single rule
 * scoped to the app's runtime surface via native CSS nesting, so every selector
 * only ever matches inside THAT app — it can never leak to the Sapiensly platform
 * chrome or to another app. The same guard rejects the handful of constructs that
 * would break isolation or the `<style>` sandbox (validated at authoring, and
 * stripped again here as defense-in-depth).
 */
final class ScopedAppCss
{
    /** The class both the runtime page surface and the Builder preview carry. */
    public const SCOPE = '.sp-app-surface';

    public const MAX_LENGTH = 20000;

    /**
     * The budget for a landing surface (settings.surface="landing").
     *
     * This used to be 60,000 — 3× the app budget above, a multiple of another
     * number rather than a measurement of what a landing needs. Two things make
     * that the wrong shape. A landing's whole look is its CSS, and on top of
     * that the CSS stands in for JavaScript the page may not ship: every hover
     * that a React original wrote as onMouseEnter, every transition and every
     *
     * @keyframes lands here. We raised the demand and left the budget alone.
     *
     * And the limit was counting the wrong thing. CSS is repetitive and
     * compresses accordingly — measured on this app's own stylesheet, 235,912
     * bytes gzip to 34,283 (14.5%). At that ratio 200,000 chars is about 29 KB
     * over the wire, less than one badly-exported product photo. The budget was
     * never protecting the visitor; it was costing the build. Observed live: a
     * model spent a whole turn compacting its stylesheet to fit, and compaction
     * is where fidelity dies — what looks redundant under pressure is the base
     * rule whose override lives in a media query.
     *
     * A ceiling still belongs here, as a guard against a model in a loop
     * writing megabytes. 200,000 is that guard, not a design constraint.
     * Callers pass it to issues(); the schema ceiling matches.
     */
    public const LANDING_MAX_LENGTH = 200000;

    /**
     * Constructs never allowed in app custom CSS, mapped to author-facing reasons.
     *
     * @var array<string, string>
     */
    private const FORBIDDEN = [
        '/<\s*\/?\s*(?:style|script)\b/i' => '<style>/<script> tags are not allowed in custom CSS.',
        '/@import\b/i' => '@import is not allowed — inline the styles instead. For fonts: the platform ships a curated self-hosted catalog (Fraunces, Instrument Serif, Bricolage Grotesque, Archivo, IBM Plex Mono, Alfa Slab One, Anton) you can reference directly, and ANY other Google Fonts family loads via settings.fonts (e.g. ["Space Grotesk:400,700"]) — never via @import.',
        '/\bexpression\s*\(/i' => 'CSS expression(...) is not allowed.',
        '/javascript\s*:/i' => 'javascript: URLs are not allowed.',
        '/\bbehavior\s*:/i' => 'CSS behavior is not allowed.',
        '/-moz-binding\b/i' => '-moz-binding is not allowed.',
    ];

    /**
     * Author-facing problems with a custom CSS string (empty array = clean). Used
     * by the manifest validator to reject at save time with a clear message.
     *
     * @return list<string>
     */
    public static function issues(?string $css, int $maxLength = self::MAX_LENGTH): array
    {
        $css = (string) $css;
        if ($css === '') {
            return [];
        }

        $issues = [];
        if (mb_strlen($css) > $maxLength) {
            $issues[] = 'Custom CSS exceeds the '.$maxLength.'-character limit'
                .($maxLength < self::LANDING_MAX_LENGTH
                    ? ' (a landing surface — settings.surface="landing" — may use up to '.self::LANDING_MAX_LENGTH.').'
                    : '.');
        }
        foreach (self::FORBIDDEN as $pattern => $message) {
            if (preg_match($pattern, $css) === 1) {
                $issues[] = $message;
            }
        }

        return $issues;
    }

    /**
     * Wrap author CSS in the scope via CSS nesting. Empty/blank input → ''. A
     * defense-in-depth pass neutralizes any tag-breakout even if validation was
     * bypassed; the result is safe to drop verbatim into a <style> element's
     * textContent.
     *
     * Two transforms keep author intent alive inside the nesting wrapper:
     *  - `@keyframes` blocks are HOISTED outside the scoped rule — nested inside
     *    a style rule they are invalid CSS and silently dropped, killing every
     *    `animation:` that references them (observed: a landing's breathing
     *    rings and float orbs all dead).
     *  - Top-level `:root` / `html` / `body` selectors are rewritten to `&`
     *    (the surface itself) — nested they become descendant selectors that
     *    match nothing, so the author's CSS variables and page background
     *    silently die and every var() falls back to garbage (observed: an
     *    entire landing rendering unstyled).
     */
    public static function compile(?string $css, string $scope = self::SCOPE): string
    {
        $css = trim((string) $css);
        if ($css === '') {
            return '';
        }

        // Backstop: a <style>/<script> tag could escape the sandbox — remove any.
        $css = preg_replace('/<\s*\/?\s*(?:style|script)\b[^>]*>?/i', '', $css) ?? '';

        [$css, $keyframes] = self::extractKeyframes($css);

        // Root-level selectors that cannot work nested → the surface itself.
        $css = preg_replace(
            '/(^|[}{;])(\s*)(?:(?::root|html|body)\s*,?\s*)+\{/',
            '$1$2& {',
            $css,
        ) ?? $css;

        $out = $scope." {\n".trim($css)."\n}";
        if ($keyframes !== '') {
            // Keyframes are global by name; authors already prefix them.
            $out .= "\n".$keyframes;
        }

        return $out;
    }

    /**
     * Split out top-level `@keyframes … { … }` blocks (balanced braces, one
     * nesting level inside for the percent stops) so they can be emitted
     * OUTSIDE the scope wrapper, where they are valid.
     *
     * @return array{0: string, 1: string} [css without keyframes, keyframes]
     */
    private static function extractKeyframes(string $css): array
    {
        $keyframes = [];
        $offset = 0;
        while (($start = stripos($css, '@keyframes', $offset)) !== false) {
            $open = strpos($css, '{', $start);
            if ($open === false) {
                break;
            }
            $depth = 0;
            $end = null;
            for ($i = $open, $len = strlen($css); $i < $len; $i++) {
                if ($css[$i] === '{') {
                    $depth++;
                } elseif ($css[$i] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $end = $i;
                        break;
                    }
                }
            }
            if ($end === null) {
                break; // unbalanced — leave as-is rather than mangle
            }
            $keyframes[] = substr($css, $start, $end - $start + 1);
            $css = substr($css, 0, $start).substr($css, $end + 1);
            $offset = $start;
        }

        return [$css, implode("\n", $keyframes)];
    }
}
