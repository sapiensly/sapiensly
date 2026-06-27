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
    public static function issues(?string $css): array
    {
        $css = (string) $css;
        if ($css === '') {
            return [];
        }

        $issues = [];
        if (mb_strlen($css) > self::MAX_LENGTH) {
            $issues[] = 'Custom CSS exceeds the '.self::MAX_LENGTH.'-character limit.';
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
     */
    public static function compile(?string $css, string $scope = self::SCOPE): string
    {
        $css = trim((string) $css);
        if ($css === '') {
            return '';
        }

        // Backstop: a <style>/<script> tag could escape the sandbox — remove any.
        $css = preg_replace('/<\s*\/?\s*(?:style|script)\b[^>]*>?/i', '', $css) ?? '';

        return $scope." {\n".$css."\n}";
    }
}
