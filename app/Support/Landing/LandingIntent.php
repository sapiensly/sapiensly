<?php

namespace App\Support\Landing;

/**
 * Detects landing/website INTENT in a user's build request — the deterministic
 * counterpart to prompt rule 1d-land for the decisions that happen BEFORE the
 * app is tagged as a landing. Observed live (twice): a weak model's very first
 * move on "quiero una landing…" was `scaffold_app`, banking a CRUD skeleton the
 * whole build then sat on. Two consumers:
 *
 *  - ScaffoldAppTool refuses to scaffold a landing-intent request (with an
 *    explicit override arg for false positives), and
 *  - BuilderAiService::moduleFor() routes the turn to the `landing_builder`
 *    model default from the FIRST turn, not only after tagging.
 *
 * Matching is accent- and case-insensitive. Two tiers keep the common app path
 * safe: strong markers (landing, página web, sitio web, website, …) always
 * match; bare "página"/"sitio" match only when the text does NOT also talk
 * about an app/dashboard build ("una app con páginas por objeto" must scaffold).
 */
final class LandingIntent
{
    private const STRONG = '/\b(landing|website|web\s?page|one\s?-?pager|pagina\s+web|sitio\s+web|pagina\s+de\s+aterrizaje|micrositio|microsite)\b/u';

    private const BARE = '/\b(pagina|sitio)\b/u';

    private const APPISH = '/\b(app|apps|aplicacion|aplicaciones|crud|dashboard|tablero|inventario|sistema)\b/u';

    public static function matches(?string $text): bool
    {
        $t = self::normalize($text);
        if ($t === '') {
            return false;
        }

        if (preg_match(self::STRONG, $t) === 1) {
            return true;
        }

        return preg_match(self::BARE, $t) === 1 && preg_match(self::APPISH, $t) !== 1;
    }

    private static function normalize(?string $text): string
    {
        $t = mb_strtolower(trim((string) $text));

        return strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);
    }
}
