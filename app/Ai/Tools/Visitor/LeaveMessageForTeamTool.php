<?php

namespace App\Ai\Tools\Visitor;

use App\Services\Chatbots\ConversationEscalator;
use App\Support\Ai\PublicTurnContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool as ToolContract;
use Laravel\Ai\Tools\Request as AiRequest;
use Stringable;

/**
 * Lets a public bot actually take someone's message, instead of merely saying it
 * did.
 *
 * The gap this closes was found by testing the finished feature in a browser,
 * which is the only way it could have been found: every unit of it worked. The
 * bot said "he registrado tu mensaje con tu correo — alguien del equipo se
 * pondrá en contacto contigo", and the database held no escalation, no contact
 * and no address. Escalation was reachable only from a scripted `human_handoff`
 * node, and the overwhelmingly common bot — one LLM agent, no scripted menu —
 * has no such node and never could reach it. So the instructions promised a
 * capture the product had no way to perform: the same lie the grounding work
 * removed, one level up.
 *
 * A tool is the right shape for it because that is how agents touch the world
 * here, and because the model already decides when someone wants a person — it
 * just had nothing to call. This is the only tool a public visitor's turn gets;
 * the platform catalogue stays withheld (see PublicTurnContext).
 */
class LeaveMessageForTeamTool implements ToolContract
{
    public function name(): string
    {
        return 'leave_message_for_the_team';
    }

    public function description(): Stringable|string
    {
        return 'Record that this person wants a human, so the team is notified and can follow up. '
            .'Call it when they ask for a person, ask to be called, or leave a message for someone — '
            .'and call it BEFORE telling them their message is registered, because this tool is what '
            .'registers it. Pass their email if they gave one; without it nobody can reply. '
            .'Calling it does not connect anyone to the chat: no one joins this conversation.';
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'reason' => $schema->string()->description('One line, for the team: what this person needs.')->required(),
            'email' => $schema->string()->description('Their email address, exactly as they gave it. Omit if they have not given one.'),
            'name' => $schema->string()->description('Their name, if they gave one.'),
        ];
    }

    public function handle(AiRequest $request): Stringable|string
    {
        $context = app(PublicTurnContext::class);
        $conversation = $context->conversation();
        $chatbot = $context->chatbot();

        // Outside a widget turn there is no conversation to escalate. Say so
        // plainly rather than throwing: the model's next sentence to the visitor
        // depends on this answer, and a bot that thinks it took a message it did
        // not take is the entire bug.
        if ($conversation === null || $chatbot === null) {
            return 'Could not take a message: this conversation cannot be escalated. Do not tell them their message was registered.';
        }

        $args = $request->toArray();
        $email = trim((string) ($args['email'] ?? ''));

        $this->escalator()->escalate(
            $chatbot,
            $conversation,
            reason: trim((string) ($args['reason'] ?? '')) ?: null,
            visitorName: trim((string) ($args['name'] ?? '')) ?: null,
            visitorEmail: $email !== '' ? $email : null,
        );

        return $email === ''
            ? 'Message recorded for the team. No email on file — ask for one so they can be answered.'
            : "Message recorded for the team, to be answered at {$email}.";
    }

    private function escalator(): ConversationEscalator
    {
        return app(ConversationEscalator::class);
    }
}
