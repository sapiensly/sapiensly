<?php

namespace App\Services\Chatbots;

use App\Enums\MessageRole;
use App\Models\Agent;
use App\Models\Message;
use App\Models\User;
use App\Services\LLMService;
use App\Services\RetrievalService;
use App\Support\Ai\PublicTurnContext;
use App\Support\Chatbots\Evaluation\EvalCase;
use App\Support\Chatbots\Evaluation\EvalOutcome;
use App\Support\Tenancy\TenantContext;
use Throwable;

/**
 * Runs a set of questions past a bot and reports how it did.
 *
 * This exists because of a gap the audit made obvious: the instructions that
 * govern every answer the product gives were rewritten — answer from the
 * material, never from general knowledge, admit the gap instead of filling it —
 * and there was no way whatsoever to show the change helped. Tests can assert
 * that an instruction is present in a prompt. Only this can tell you whether the
 * bot behaves differently because of it.
 *
 * The retrieval step is substituted per case, so a run does not depend on what
 * happens to be in a vector store today: the case declares the passage the bot
 * should be answering from, or declares that there is none. What stays real is
 * the part being measured — the same prompt assembly and the same model the
 * product ships.
 *
 * It therefore costs money and cannot live in the test suite. It is a command
 * you run before and after touching a prompt.
 */
class ChatbotEvaluator
{
    /** The stand-in knowledge base a case's material is delivered under. */
    public const EVAL_KNOWLEDGE_BASE_ID = 'kb_eval';

    /**
     * @param  User|null  $owner  whoever pays for the bot's turns — the same
     *                            binding the widget does, and the reason the
     *                            provider keys reach the SDK at all
     * @param  list<EvalCase>  $cases
     * @return list<EvalOutcome>
     */
    public function run(Agent $agent, array $cases, ?User $owner = null): array
    {
        // A visitor's turn is answered as the owner: their provider config, their
        // tenant scope. Skipping this is not a smaller evaluation, it is a failed
        // one — every call comes back 401 on installs whose keys live in
        // `ai_providers` rather than the environment.
        if ($owner !== null) {
            app(TenantContext::class)->set($owner->organization_id, $owner->id);
        }

        $subject = $this->agentThatRetrieves($agent);

        $outcomes = [];

        foreach ($cases as $case) {
            $outcomes[] = EvalOutcome::grade($case, $this->ask($subject, $case, $owner));
        }

        return $outcomes;
    }

    /**
     * The same agent, told in memory that it has one knowledge base.
     *
     * Retrieval only runs for an agent with something attached, so evaluating a
     * bot with none silently skipped the substitution and graded answers the test
     * material had never reached — a full run of confident numbers about nothing,
     * and the report looked completely normal. Only the answer to "do you have
     * material?" is forced; the case's own passage still decides what the bot
     * sees, and nothing is written.
     */
    private function agentThatRetrieves(Agent $agent): Agent
    {
        return $agent->withKnowledgeBaseIdsInMemory([self::EVAL_KNOWLEDGE_BASE_ID]);
    }

    /**
     * One turn, through the path a visitor's question actually takes — public
     * trust boundary included, so a bot evaluated here is the bot that answers.
     */
    private function ask(Agent $agent, EvalCase $case, ?User $owner): string
    {
        $this->substituteRetrieval($case);

        // A fresh LLMService per case, and only after the substitution is in
        // place: it memoizes the retrieval service on first use, so one instance
        // reused across the set answered every later question out of the FIRST
        // case's material — six cases, one passage, and a plausible-looking score.
        $llm = app(LLMService::class);
        if ($owner !== null) {
            $llm->setContext($owner);
        }

        $message = new Message;
        $message->role = MessageRole::User;
        $message->content = $case->question;

        try {
            return app(PublicTurnContext::class)->runPublic(function () use ($llm, $agent, $message): string {
                $result = $llm->chatWithKnowledgeAndTools($agent, [$message]);

                return (string) ($result['response']->text ?? '');
            });
        } catch (Throwable $e) {
            // A turn that blew up is a failed case, not a failed run: the rest of
            // the set still has something to say.
            return '[evaluation error] '.$e->getMessage();
        }
    }

    /**
     * Bind a retrieval service that returns exactly what this case says the
     * knowledge base holds — including nothing.
     */
    private function substituteRetrieval(EvalCase $case): void
    {
        app()->instance(RetrievalService::class, new class($case->context) extends RetrievalService
        {
            public function __construct(private readonly ?string $context) {}

            public function retrieve(
                string $query,
                array $knowledgeBaseIds,
                int $topK = 5,
                float $threshold = 0.7,
            ): array {
                return [
                    'context' => $this->context ?? '',
                    'knowledge_bases' => $this->context === null ? [] : [['id' => ChatbotEvaluator::EVAL_KNOWLEDGE_BASE_ID, 'name' => 'Eval set']],
                    'chunk_count' => $this->context === null ? 0 : 1,
                ];
            }
        });
    }
}
