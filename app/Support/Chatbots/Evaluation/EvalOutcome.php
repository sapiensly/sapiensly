<?php

namespace App\Support\Chatbots\Evaluation;

use Illuminate\Support\Str;

/**
 * How one case went, and — when it went badly — why, in the terms the person
 * reading the report can act on.
 *
 * "Failed" is never enough on its own here: the whole point of the harness is to
 * be run after a prompt change, and a bare pass rate does not tell you which
 * sentence you broke.
 */
final class EvalOutcome
{
    /**
     * @param  list<string>  $failures
     */
    private function __construct(
        public readonly EvalCase $case,
        public readonly string $answer,
        public readonly array $failures,
    ) {}

    /**
     * Grade an answer against a case.
     */
    public static function grade(EvalCase $case, string $answer): self
    {
        $haystack = Str::lower($answer);
        $failures = [];

        foreach ($case->mustContain as $needle) {
            if (! str_contains($haystack, Str::lower($needle))) {
                $failures[] = "did not carry «{$needle}» from the material";
            }
        }

        if ($case->mustContainAny !== [] && ! self::containsAny($haystack, $case->mustContainAny)) {
            $failures[] = 'said none of «'.implode('», «', $case->mustContainAny).'»';
        }

        foreach ($case->mustNotContain as $needle) {
            if (str_contains($haystack, Str::lower($needle))) {
                $failures[] = "said «{$needle}», which the material never did";
            }
        }

        if ($case->mustRefuse && ! self::admitsTheGap($haystack)) {
            $failures[] = 'answered as if it knew, when it had nothing to answer from';
        }

        if (! $case->mustRefuse && self::admitsTheGap($haystack) && $case->mustContain !== []) {
            $failures[] = 'refused a question the material did answer';
        }

        return new self($case, $answer, $failures);
    }

    /**
     * @param  list<string>  $needles
     */
    private static function containsAny(string $loweredAnswer, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($loweredAnswer, Str::lower($needle))) {
                return true;
            }
        }

        return false;
    }

    public function passed(): bool
    {
        return $this->failures === [];
    }

    /**
     * Both directions of the refusal check read this: a bot that never admits a
     * gap invents, and one that admits it too readily is useless. The markers
     * are phrase-level rather than a model call, so the verdict is the same on
     * every run.
     */
    private static function admitsTheGap(string $loweredAnswer): bool
    {
        foreach (EvalCase::REFUSAL_MARKERS as $marker) {
            if (str_contains($loweredAnswer, $marker)) {
                return true;
            }
        }

        return false;
    }
}
