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
 * safe: strong markers (landing, página web, sitio web, website, …) match
 * unless they read as a field name rather than as the thing being built (see
 * {@see weighStrongMarkers}); bare "página"/"sitio" match only when the text does
 * NOT also talk about an app/dashboard build ("una app con páginas por objeto"
 * must scaffold).
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

        [$isIntent, $withoutFieldNames] = self::weighStrongMarkers($t);

        if ($isIntent) {
            return true;
        }

        // A marker dismissed as a field name must not come back through the
        // bare tier: "sitio web" contains "sitio", so leaving it in the text
        // would re-match everything the tier above just excused.
        return preg_match(self::BARE, $withoutFieldNames) === 1
            && preg_match(self::APPISH, $withoutFieldNames) !== 1;
    }

    /**
     * Whether a strong marker names what is being BUILT.
     *
     * "sitio web" and "website" are also ordinary FIELDS on a customer or
     * supplier record, and there they arrive as one item in a comma-separated
     * list of attributes — "telefono, sitio web, direccion". A build request
     * never phrases itself that way, so an occurrence fenced by commas is read
     * as a field name. Observed live: a field-service brief with eight CRUD
     * entities matched on that one field, which refused the scaffold AND
     * silently billed the whole turn to the landing model.
     *
     * The APPISH guard cannot do this job — "quiero una landing para mi app de
     * inventario" is a landing request that also talks about an app — so the
     * distinction has to be positional, not vocabulary.
     *
     * @return array{0: bool, 1: string} whether any occurrence reads as intent,
     *                                   and the text with the field-name ones removed
     */
    private static function weighStrongMarkers(string $t): array
    {
        if (preg_match_all(self::STRONG, $t, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return [false, $t];
        }

        $fieldNames = [];

        foreach ($matches[0] as [$match, $offset]) {
            if (! self::isEnumerationItem($t, $offset, strlen($match))) {
                return [true, $t];
            }

            $fieldNames[] = $match;
        }

        return [false, str_replace($fieldNames, ' ', $t)];
    }

    /** Is this occurrence just one item in a comma-separated list? */
    private static function isEnumerationItem(string $t, int $offset, int $length): bool
    {
        $before = rtrim(substr($t, 0, $offset));
        $after = ltrim(substr($t, $offset + $length));

        if (! str_ends_with($before, ',')) {
            return false;
        }

        return $after === ''
            || str_starts_with($after, ',')
            || str_starts_with($after, '.')
            || str_starts_with($after, ';')
            || str_starts_with($after, ')')
            || str_starts_with($after, 'y ')
            || str_starts_with($after, 'and ');
    }

    private static function normalize(?string $text): string
    {
        $t = mb_strtolower(trim((string) $text));

        return strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);
    }
}
