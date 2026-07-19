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
     * The budget for a landing surface (settings.surface="landing"): a landing's
     * bespoke look IS its CSS, so it gets 3× the app/dashboard budget. Callers
     * pass it to issues(); the schema ceiling matches.
     */
    public const LANDING_MAX_LENGTH = 60000;

    /**
     * Constructs never allowed in app custom CSS, mapped to author-facing reasons.
     *
     * @var array<string, string>
     */
    private const FORBIDDEN = [
        '/<\s*\/?\s*(?:style|script)\b/i' => '<style>/<script> tags are not allowed in custom CSS.',
        '/@import\b/i' => '@import is not allowed — inline the styles instead.',
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
