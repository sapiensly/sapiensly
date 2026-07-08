<?php

namespace App\Support\Apps;

use App\Models\App;
use Illuminate\Support\Str;

/**
 * Names an app from its first builder prompt — like a chat titling itself from
 * the opening message. Deterministic and synchronous (no model call), so the
 * first turn stays snappy; the builder header + runtime slug update immediately.
 */
class AppNaming
{
    /** Placeholder name a freshly-created app carries until its first prompt. */
    public const UNTITLED = 'Nueva app';

    /** A leading build verb ("crea", "hazme", "build"…) + an optional article. */
    private const LEAD_VERB = '/^\s*(por favor,?\s+)?(cre[ae]\w*|h[aá]z\w*|gener[ae]\w*|arm[ae]\w*|constru\w*|dise[ñn][ae]\w*|dame|quiero|necesito|build|creat\w*|make|generat\w*|design)\s+(un[ao]?|el|la|los|las|mi|a|an|the)?\s*/iu';

    /**
     * A human app name distilled from the first prompt: strip the lead verb, cap
     * the length at a word boundary, sentence-case. Null when there's nothing
     * usable (the caller keeps the placeholder).
     */
    public static function nameFromPrompt(string $prompt): ?string
    {
        $clean = self::clean($prompt);
        if ($clean === '') {
            return null;
        }
        $clean = trim((string) preg_replace(self::LEAD_VERB, '', $clean));
        if ($clean === '') {
            return null;
        }

        if (mb_strlen($clean) > 60) {
            $clean = (string) preg_replace('/\s+\S*$/u', '', mb_substr($clean, 0, 60));
        }

        return Str::ucfirst(trim($clean)) ?: null;
    }

    /** A one-line description: the cleaned prompt, sentence-cased and bounded. */
    public static function descriptionFromPrompt(string $prompt): ?string
    {
        $clean = self::clean($prompt);

        return $clean === '' ? null : Str::ucfirst(Str::limit($clean, 480));
    }

    /**
     * Force a description down to ONE sentence: keep everything up to (and
     * including) the first sentence terminator that ends a sentence, drop the
     * rest. A phrase with no terminator is returned whole (still one statement).
     * Best-effort — a terminator inside "Dr." would cut early, but a one-line
     * app description rarely carries abbreviations.
     */
    public static function firstSentence(string $text): string
    {
        $clean = self::clean($text);
        if ($clean === '') {
            return '';
        }
        if (preg_match('/^.*?[.!?…](?=\s|$)/u', $clean, $m) === 1) {
            return trim($m[0]);
        }

        return $clean;
    }

    /**
     * A slug unique within the owner's tenant, derived from a base string. Uses
     * the manifest slug grammar (lowercase, digits, underscores; starts with a
     * letter) and suffixes _2, _3… on collision.
     */
    public static function uniqueSlug(string $base, ?string $organizationId): string
    {
        $slug = self::slugify($base);
        $candidate = $slug;
        $n = 2;
        while (self::slugTaken($candidate, $organizationId)) {
            $candidate = $slug.'_'.$n++;
        }

        return $candidate;
    }

    private static function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strip_tags($text)));
    }

    private static function slugify(string $base): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', Str::lower(Str::ascii($base))), '_');
        if ($slug === '' || preg_match('/^[a-z]/', $slug) !== 1) {
            $slug = 'app'.($slug !== '' ? '_'.$slug : '');
        }

        return Str::limit($slug, 40, '');
    }

    private static function slugTaken(string $slug, ?string $organizationId): bool
    {
        return App::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('slug', $slug)
            ->exists();
    }
}
