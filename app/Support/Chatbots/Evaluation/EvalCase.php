<?php

namespace App\Support\Chatbots\Evaluation;

/**
 * One question put to a bot, and what a good answer to it has to look like.
 *
 * Expectations are deliberately mechanical — substrings the answer must carry,
 * substrings it must not, and whether the bot was supposed to admit it did not
 * know. That is narrower than a human's sense of "a good answer", and it is
 * chosen on purpose: a check that never disagrees with itself can be run before
 * and after a prompt change and the difference means something. A model judging
 * the answers would grade the same reply differently on two runs, which is the
 * one thing a regression harness cannot afford.
 *
 * The unmeasurable rest — tone, tact, whether the phrasing lands — is what a
 * human reads the transcript for. This is for catching the answer that quietly
 * invented a price.
 */
final class EvalCase
{
    /**
     * @param  string  $question  what the visitor asks
     * @param  string|null  $context  the passage retrieval should return, or null to
     *                                simulate a knowledge base that has nothing
     * @param  list<string>  $mustContain  fragments a grounded answer has to carry
     * @param  list<string>  $mustNotContain  fragments that betray invention
     * @param  list<string>  $mustContainAny  at least one of these, for facts a
     *                                        model can state in more than one
     *                                        wording ("correo" / "email"). A
     *                                        single exact phrase would fail a
     *                                        perfectly grounded answer and send
     *                                        someone hunting a bug in the bot.
     * @param  bool  $mustRefuse  whether the bot is expected to say it does not know
     */
    public function __construct(
        public readonly string $id,
        public readonly string $question,
        public readonly ?string $context = null,
        public readonly array $mustContain = [],
        public readonly array $mustNotContain = [],
        public readonly bool $mustRefuse = false,
        public readonly array $mustContainAny = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            question: (string) ($data['question'] ?? ''),
            context: isset($data['context']) && is_string($data['context']) ? $data['context'] : null,
            mustContain: array_values(array_filter((array) ($data['must_contain'] ?? []), 'is_string')),
            mustNotContain: array_values(array_filter((array) ($data['must_not_contain'] ?? []), 'is_string')),
            mustRefuse: (bool) ($data['must_refuse'] ?? false),
            mustContainAny: array_values(array_filter((array) ($data['must_contain_any'] ?? []), 'is_string')),
        );
    }

    /** Phrases that count as the bot admitting the gap, in either language. */
    public const REFUSAL_MARKERS = [
        'no tengo', 'no cuento con', 'no dispongo', 'no está en', 'no aparece',
        'no lo tengo', 'no tengo esa', 'no tengo información',
        "don't have", 'do not have', "isn't something i have", 'not something i have',
        'no information', 'not on file',
    ];
}
