<?php

namespace App\Support\Locale;

use Illuminate\Support\Str;

/**
 * Best-effort language of a short user prompt, over the locales the platform's
 * deterministic layer speaks (es, en, pt, fr — see {@see SemanticLexicon}).
 * Deterministic and dependency-free: a scored heuristic over each language's
 * commonest FUNCTION words plus its DISTINCTIVE letters/punctuation. Content
 * words and tech loanwords ("dashboard") are shared across all four and left out
 * to avoid ties. Returns null when no language clearly wins, so the caller falls
 * back to its own default.
 */
class PromptLanguage
{
    /**
     * Function words per language — chosen to DISTINGUISH the four, so a word
     * shared across languages (Spanish/Portuguese "de", "para") mostly appears in
     * only one list. Build verbs and articles carry the signal.
     *
     * @var array<string, list<string>>
     */
    private const WORDS = [
        'es' => [
            'de', 'la', 'el', 'los', 'las', 'un', 'una', 'unos', 'unas', 'del', 'al',
            'y', 'en', 'con', 'por', 'para', 'que', 'como', 'donde', 'segun',
            'crea', 'crear', 'haz', 'hacer', 'genera', 'generar', 'arma', 'armar',
            'quiero', 'necesito', 'dame', 'muestrame', 'mi', 'mis', 'sus', 'este', 'esta',
            'sobre', 'nuestro', 'nuestra',
        ],
        'en' => [
            'the', 'of', 'for', 'with', 'and', 'to', 'by', 'in', 'on', 'from',
            'create', 'make', 'build', 'show', 'give', 'want', 'need',
            'my', 'our', 'this', 'that', 'these', 'those', 'how', 'where', 'which',
        ],
        'pt' => [
            'da', 'do', 'das', 'dos', 'um', 'uma', 'com', 'para', 'pelo', 'pela',
            'nao', 'voce', 'meu', 'minha', 'seu', 'sua', 'isso', 'ao',
            'crie', 'criar', 'gere', 'gerar', 'faca', 'quero', 'preciso', 'mostre',
            'sistema', 'aplicativo', 'painel',
        ],
        'fr' => [
            'le', 'les', 'une', 'des', 'du', 'au', 'aux', 'avec', 'pour', 'dans', 'sur',
            'vous', 'je', 'mon', 'ma', 'mes', 'votre', 'cette', 'ce', 'ces',
            'creer', 'cree', 'generer', 'gerer', 'faire', 'veux', 'besoin', 'montre',
            'systeme', 'tableau', 'application',
        ],
    ];

    /**
     * Words that essentially do not occur in the other three, weighted to
     * outrank the shared ones.
     *
     * The plain lists above claim each word "mostly appears in only one list",
     * and as lists that is true — but the TEXTS overlap anyway: Portuguese and
     * French are both full of "de", "que", "para", "la", "en", which sit in the
     * Spanish list. On real briefs that was decisive. A Portuguese school and a
     * French law firm were both read as Spanish, so a Brazilian customer would
     * have been handed an app in the wrong language, with the currency and
     * timezone that follow it.
     *
     * These are the ones that settle it: Spanish contracts "de el" to "del" and
     * Portuguese to "do", French to "du". None of the three borrows another's.
     *
     * @var array<string, list<string>>
     */
    private const STRONG = [
        'es' => ['el', 'los', 'las', 'del', 'al', 'con', 'por', 'segun', 'cada'],
        'pt' => ['da', 'do', 'das', 'dos', 'nao', 'voce', 'pelo', 'pela', 'sao', 'situacao', 'mes'],
        'fr' => ['le', 'les', 'des', 'du', 'aux', 'avec', 'cette', 'chaque', 'doit', 'sont', 'qui', 'leur'],
        'en' => ['the', 'of', 'and', 'with', 'each', 'are', 'is', 'needs'],
    ];

    /**
     * Letters/punctuation only ONE of the four uses — a strong, near-certain
     * signal worth two function words. Shared accents (á é í ó ú) are deliberately
     * excluded: they appear in es, pt AND fr, so they cannot separate them.
     *
     * @var array<string, string>
     */
    private const CHARS = [
        'es' => '/[ñ¿¡]/iu',
        'pt' => '/[ãõ]/iu',
        'fr' => '/[èùœêâîôûë]/iu',
    ];

    /**
     * @return 'es'|'en'|'pt'|'fr'|null
     */
    public static function detect(string $text): ?string
    {
        $lower = Str::lower(trim($text));
        if ($lower === '') {
            return null;
        }

        // Fold accents for WORD counting so "créer"/"gestão"/"você" match the
        // ASCII word lists; the DISTINCTIVE-char check below still reads the
        // original text (where ã/õ/è survive) — that is where accents carry signal.
        $counts = array_count_values(
            preg_split('/[^\p{L}]+/u', Str::ascii($lower), -1, PREG_SPLIT_NO_EMPTY) ?: []
        );

        $scores = ['es' => 0, 'en' => 0, 'pt' => 0, 'fr' => 0];
        foreach (self::WORDS as $lang => $words) {
            foreach ($words as $word) {
                $scores[$lang] += $counts[$word] ?? 0;
            }
        }
        // Three apiece, so a handful of them beats the shared vocabulary a
        // long brief accumulates by sheer length.
        foreach (self::STRONG as $lang => $words) {
            foreach ($words as $word) {
                $scores[$lang] += 3 * ($counts[$word] ?? 0);
            }
        }
        foreach (self::CHARS as $lang => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $scores[$lang] += 2;
            }
        }

        arsort($scores);
        $ranked = array_values($scores);
        $winner = array_key_first($scores);

        // No signal, or a tie for first place → too ambiguous to call.
        if ($ranked[0] === 0 || $ranked[0] === $ranked[1]) {
            return null;
        }

        return $winner;
    }
}
