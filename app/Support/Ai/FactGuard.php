<?php

namespace App\Support\Ai;

/**
 * The one thing a model may never do to a number.
 *
 * Everywhere in this codebase an LLM is allowed to touch human-facing copy, the
 * contract is the same: it may change the VOICE, never the FACTS. The copy it
 * refines was derived from real data — that is the whole point of computing the
 * facts first — and a rewrite that quietly moves a figure turns a grounded card
 * into a confident lie, which is worse than no card at all.
 *
 * Until this existed, exactly one of those paths enforced it. The Express voice
 * gate checked only that the model returned the right NUMBER of cards, so a
 * model handing back four cards with four invented figures was accepted whole and
 * overwrote the grounded bodies. The deck narrator's prompt said "NEVER touch
 * labels/series/values" and nothing made it true: the model returns a full slide
 * object, and the validator downstream checks structure, not fidelity.
 *
 * Two different questions, because two different jobs:
 *
 *   - REWRITING an existing sentence → the numbers it carried must survive
 *     ({@see keepsNumbers}). Dropping one is how "80% of the backlog sits in 4
 *     reasons" becomes "most of the backlog sits in a few reasons".
 *   - AUTHORING new prose about known data → every number in it must come FROM
 *     that data ({@see onlyKnownNumbers}). This is the check that catches an
 *     invented figure, which no amount of preservation-checking can.
 *
 * And for a structured rewrite (a whole slide handed back), the fields the model
 * was allowed to touch are the only ones that may differ ({@see keepsValues}).
 *
 * Every check fails CLOSED: when in doubt, the deterministic original stands.
 */
class FactGuard
{
    /** Any run of digits, with the separators a narrative writes them with. */
    private const NUMBER = '/\d[\d.,]*/';

    /**
     * Does the rewrite still carry every number the original stated?
     */
    public static function keepsNumbers(string $original, string $rewrite): bool
    {
        foreach (self::numbersIn($original) as $number) {
            if (! in_array($number, self::numbersIn($rewrite), true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A rewrite that is safe to accept, or null to keep what you had.
     *
     * Empty is not an improvement, and neither is a rewrite that lost a fact.
     */
    public static function safeRewrite(string $original, mixed $rewrite): ?string
    {
        if (! is_string($rewrite)) {
            return null;
        }
        $rewrite = trim($rewrite);

        return ($rewrite !== '' && self::keepsNumbers($original, $rewrite)) ? $rewrite : null;
    }

    /**
     * Is every number in this prose one the data actually contains?
     *
     * The complement of {@see keepsNumbers}: that one catches a fact going
     * missing, this one catches a fact being invented. Newly authored prose has
     * no original to preserve, so the only defensible test is that it says
     * nothing the ground truth doesn't.
     *
     * @param  string  $groundTruth  everything the model was given (facts, figures, source text)
     */
    public static function onlyKnownNumbers(string $prose, string $groundTruth): bool
    {
        $known = self::numbersIn($groundTruth);

        foreach (self::numbersIn($prose) as $number) {
            if (! in_array($number, $known, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Did a structured rewrite touch only the fields it was allowed to?
     *
     * A model handed a whole object back can change anything in it. The prose
     * fields are the ones it was asked to fix; every other key — a value, a
     * series, a label — must come back exactly as it went in.
     *
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $rewritten
     * @param  list<string>  $proseFields  the keys the model was invited to change
     */
    public static function keepsValues(array $original, array $rewritten, array $proseFields): bool
    {
        // A key the model dropped or invented is a structural change, not a
        // rewrite — whichever side it appears on.
        $keys = array_unique([...array_keys($original), ...array_keys($rewritten)]);

        foreach ($keys as $key) {
            if (in_array($key, $proseFields, true)) {
                continue;
            }
            if (! array_key_exists($key, $original) || ! array_key_exists($key, $rewritten)) {
                return false;
            }
            if ($original[$key] !== $rewritten[$key]) {
                return false;
            }
        }

        return true;
    }

    /**
     * The numbers a string states, normalised so that the same figure written two
     * ways reads as one: 1,234 and 1234 are the same number, and so are 40 and
     * 40.0. Without this, a rewrite would be rejected for formatting.
     *
     * @return list<string>
     */
    private static function numbersIn(string $text): array
    {
        preg_match_all(self::NUMBER, $text, $matches);

        return array_values(array_unique(array_map(
            static function (string $raw): string {
                // Trailing punctuation belongs to the sentence, not the figure.
                $raw = rtrim($raw, '.,');
                // Thousands separators only ever sit before a group of three.
                $raw = preg_replace('/[.,](?=\d{3}\b)/', '', $raw) ?? $raw;
                // 40.0 and 40 are the same claim.
                if (str_contains($raw, '.')) {
                    $raw = rtrim(rtrim($raw, '0'), '.');
                }

                return $raw === '' ? '0' : $raw;
            },
            $matches[0],
        )));
    }
}
