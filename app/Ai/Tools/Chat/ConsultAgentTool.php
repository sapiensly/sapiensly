<?php

namespace App\Ai\Tools\Chat;

use App\Enums\MessageRole;
use App\Events\Chat\ChatAgentConsultation;
use App\Models\Agent;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Message;
use App\Models\User;
use App\Services\LLMService;
use App\Support\Chat\ConsultationLog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool as ToolContract;
use Laravel\Ai\Tools\Request as AiRequest;
use Stringable;

/**
 * A chat-scoped tool that lets the assistant consult ANOTHER agent mid-turn to
 * resolve a doubt or get a second opinion, and fold the answer back into its
 * reply. The consultation is always surfaced to the user live via
 * {@see ChatAgentConsultation} (a "Consulting <Agent>…" indicator, then the
 * answer) and persisted onto the message so it survives a reload.
 *
 * `visible=false` (default) renders a compact background pill; `visible=true`
 * brings the other agent's answer "to the front" as a card. The consulted agent
 * runs as the chat user, so it is bound by that user's data scope.
 */
class ConsultAgentTool implements ToolContract
{
    /**
     * Hard cap on consultations per assistant turn. Each consult is a full
     * (streamed) agent run that eats into the turn's job budget (280s), so an
     * unbounded chain of consults could time the whole turn out. The soft
     * guidance already tells the model to consult sparingly; this is the
     * deterministic backstop when it doesn't.
     */
    private const MAX_CONSULTATIONS_PER_TURN = 3;

    public function __construct(
        private Chat $chat,
        private ChatMessage $placeholder,
        private User $user,
        private ?Agent $currentAgent,
        private ConsultationLog $log,
    ) {}

    public function description(): Stringable|string
    {
        return 'Consult another agent in this workspace to resolve a doubt or get a second opinion, then use their answer in your reply. Use list_agents to discover available agents and their expertise. Set visible=true when the user should see the other agent\'s full answer as a card; leave it false to consult quietly in the background. Consult only when a question genuinely falls in another agent\'s domain or a decision warrants it — not for every turn.';
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()->description('The id of the agent to consult (from list_agents).')->required(),
            'question' => $schema->string()->description('A self-contained question for that agent — include the context it needs.')->required(),
            'visible' => $schema->boolean()->description('Show the consulted agent\'s answer to the user as a card (default false = background).'),
        ];
    }

    public function handle(AiRequest $request): Stringable|string
    {
        $args = $request->toArray();
        $agentId = (string) ($args['agent_id'] ?? '');
        $question = trim((string) ($args['question'] ?? ''));
        $visible = (bool) ($args['visible'] ?? false);

        if ($question === '') {
            return 'Error: provide a question for the agent.';
        }

        if ($this->currentAgent !== null && $agentId === $this->currentAgent->id) {
            return 'Error: you cannot consult yourself — answer directly.';
        }

        $target = Agent::query()->forAccountContext($this->user)->find($agentId);
        if ($target === null) {
            return "Error: no agent '{$agentId}' is available to consult. Use list_agents first.";
        }

        if ($this->log->count() >= self::MAX_CONSULTATIONS_PER_TURN) {
            return 'Error: consultation limit reached for this turn ('.self::MAX_CONSULTATIONS_PER_TURN.'). '
                .'Answer the user directly using what you already have.';
        }

        $consultationId = (string) Str::ulid();

        $this->broadcast('start', $consultationId, $target, $question, $visible, null);

        try {
            // Stream the consulted agent's turn (chatStreamed) rather than a
            // blocking prompt(): a slow reasoning agent then survives on the SSE
            // idle watchdog instead of tripping the SDK's short 60s request cap.
            $answer = app(LLMService::class)
                ->setContext($this->user)
                ->chatStreamed($target, [new Message(['role' => MessageRole::User, 'content' => $question])]);
        } catch (\Throwable $e) {
            $answer = 'The agent could not respond: '.$e->getMessage();
        }

        $this->broadcast('result', $consultationId, $target, $question, $visible, $answer);

        $this->log->add([
            'id' => $consultationId,
            'agent_id' => $target->id,
            'agent_name' => $target->name,
            'question' => $question,
            'answer' => $answer,
            'visible' => $visible,
        ]);

        return "{$target->name} replied: {$answer}";
    }

    private function broadcast(string $phase, string $consultationId, Agent $target, string $question, bool $visible, ?string $answer): void
    {
        try {
            ChatAgentConsultation::dispatch(
                $this->chat->id,
                $this->placeholder->id,
                $phase,
                $consultationId,
                $target->id,
                $target->name,
                $question,
                $visible,
                $answer,
            );
        } catch (\Throwable) {
            // Live feedback is best-effort; never break the turn over a broadcast hiccup.
        }
    }
}
