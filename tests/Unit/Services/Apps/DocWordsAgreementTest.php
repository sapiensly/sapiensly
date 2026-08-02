<?php

use App\Services\Apps\Docs\DocWords;
use Illuminate\Support\Str;

/**
 * A sentence that agrees with a gender the manifest does not carry.
 *
 * The documents are written by dropping the app's own words — object names,
 * field names — into templates. Those names arrive with no gender attached:
 * "Incidencias" is feminine, "Contratos" masculine, and nothing in the manifest
 * says which. So a Spanish, Portuguese or French template that puts an article,
 * a possessive or a participle against the placeholder is right half the time.
 *
 * Three separate rounds of this shipped and were caught by reading generated
 * output: "los Incidencias", then "seus Matrículas", then "Quantos Matrículas".
 * Each was fixed by rewriting that one phrase. This is the rule instead — the
 * word next to a name placeholder must be one that does not inflect.
 *
 * The escape is always the same: reach for an invariant word ("cada", "qué",
 * "combien"), or name the thing being counted ("un registro de {n}") so the
 * agreement lands on a noun the template owns.
 */
/**
 * Determiners, quantifiers and possessives, which agree with the noun AFTER
 * them — so one of these immediately before a placeholder agrees with the name.
 */
const AGREES_FORWARD = [
    'es' => ['el', 'la', 'los', 'las', 'un', 'una', 'unos', 'unas', 'del', 'al',
        'este', 'esta', 'estos', 'estas', 'cuantos', 'cuantas', 'todo', 'toda',
        'nuevo', 'nueva', 'otro', 'otra'],
    'pt' => ['o', 'a', 'os', 'as', 'um', 'uma', 'uns', 'umas', 'do', 'da', 'dos', 'das',
        'no', 'na', 'nos', 'nas', 'ao', 'seu', 'seus', 'sua', 'suas',
        'quantos', 'quantas', 'todo', 'toda', 'outro', 'outra'],
    'fr' => ['le', 'la', 'un', 'une', 'ce', 'cette', 'du', 'au', 'quel', 'quelle',
        'tout', 'toute', 'nouveau', 'nouvelle'],
];

/**
 * Adjectives and past participles, which agree with the noun BEFORE them — so
 * one of these immediately after a placeholder agrees with the name.
 *
 * Kept apart from the list above on purpose. A preposition after a placeholder
 * ("a soma de {f} dos registros") governs what FOLLOWS it and is perfectly
 * safe; flagging it would push the phrasing somewhere worse for no reason.
 */
const AGREES_BACKWARD = [
    'es' => ['ligado', 'ligada', 'ligados', 'ligadas', 'nuevo', 'nueva', 'mismo', 'misma', 'creado', 'creada'],
    'pt' => ['ligado', 'ligada', 'ligados', 'ligadas', 'mesmo', 'mesma', 'criado', 'criada'],
    'fr' => ['lie', 'liee', 'lies', 'liees', 'nouveau', 'nouvelle', 'meme', 'cree', 'creee'],
];

/** The placeholders filled with a name out of the manifest. */
const NAME_PLACEHOLDERS = ['{n}', '{s}', '{f}', '{list}'];

it('never puts a word that inflects next to a name from the manifest', function () {
    $offenders = [];

    foreach (AGREES_FORWARD as $lang => $forward) {
        $backward = AGREES_BACKWARD[$lang];
        $words = DocWords::for($lang);

        foreach (DocWords::keys() as $key) {
            $phrase = $words->get($key);

            foreach (NAME_PLACEHOLDERS as $placeholder) {
                $at = 0;
                while (($at = mb_strpos($phrase, $placeholder, $at)) !== false) {
                    $before = neighbourWord(mb_substr($phrase, 0, $at), last: true);
                    $after = neighbourWord(mb_substr($phrase, $at + mb_strlen($placeholder)), last: false);
                    $at += mb_strlen($placeholder);

                    if ($before !== '' && in_array($before, $forward, true)) {
                        $offenders[] = "{$lang}.{$key}: \"{$phrase}\" — \"{$before}\" before it";
                    }
                    if ($after !== '' && in_array($after, $backward, true)) {
                        $offenders[] = "{$lang}.{$key}: \"{$phrase}\" — \"{$after}\" after it";
                    }
                }
            }
        }
    }

    expect($offenders)->toBe([], "these phrases agree with a gender the manifest does not carry:\n  ".implode("\n  ", $offenders));
});

/** The word touching a placeholder, folded to bare ASCII for comparison. */
function neighbourWord(string $side, bool $last): string
{
    $words = preg_split('/[^\p{L}]+/u', $side, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $word = $last ? (string) end($words) : (string) ($words[0] ?? '');

    return mb_strtolower((string) (Str::ascii($word) ?: $word));
}
