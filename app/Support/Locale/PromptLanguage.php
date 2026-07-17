<?php

namespace App\Support\Locale;

use Illuminate\Support\Str;

/**
 * Best-effort language of a short user prompt, limited to the locales the
 * platform actually ships translations for (es, en). Deterministic and
 * dependency-free — a scored heuristic over Spanish diacritics/inverted
 * punctuation and the commonest FUNCTION words of each language (content words
 * and tech loanwords like "dashboard" are shared across both, so they are left
 * out to avoid ties). Returns null when neither language clearly wins, so the
 * caller falls back to its own default.
 */
class PromptLanguage
{
    /** Letters and punctuation only Spanish uses — a strong, near-certain signal. */
    private const SPANISH_CHARS = '/[ñ¿¡áéíóú]/iu';

    /** @var list<string> Spanish function words (articles, prepositions, build verbs). */
    private const SPANISH_WORDS = [
        'de', 'la', 'el', 'los', 'las', 'un', 'una', 'unos', 'unas', 'del', 'al',
        'y', 'en', 'con', 'por', 'para', 'que', 'como', 'cuanto', 'cuantos', 'donde',
        'crea', 'crear', 'haz', 'hacer', 'genera', 'generar', 'arma', 'armar',
        'quiero', 'necesito', 'dame', 'muestrame', 'mi', 'mis', 'sus', 'este', 'esta',
        'sobre', 'segun', 'nuestro', 'nuestra',
    ];

    /** @var list<string> English function words (articles, prepositions, build verbs). */
    private const ENGLISH_WORDS = [
        'the', 'of', 'for', 'with', 'and', 'to', 'by', 'in', 'on', 'from',
        'create', 'make', 'build', 'show', 'give', 'want', 'need',
        'my', 'our', 'this', 'that', 'these', 'those', 'how', 'where', 'which',
    ];

    /**
     * @return 'es'|'en'|null
     */
    public static function detect(string $text): ?string
    {
        $lower = Str::lower(trim($text));
        if ($lower === '') {
            return null;
        }

        $counts = array_count_values(
            preg_split('/[^\p{L}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: []
        );

        $es = 0;
        $en = 0;
        foreach (self::SPANISH_WORDS as $word) {
            $es += $counts[$word] ?? 0;
        }
        foreach (self::ENGLISH_WORDS as $word) {
            $en += $counts[$word] ?? 0;
        }

        if (preg_match(self::SPANISH_CHARS, $text) === 1) {
            $es += 2;
        }

        if ($es === $en) {
            return null;
        }

        return $es > $en ? 'es' : 'en';
    }
}
