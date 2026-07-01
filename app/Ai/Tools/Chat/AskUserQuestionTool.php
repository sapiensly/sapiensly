<?php

namespace App\Ai\Tools\Chat;

use App\Events\Chat\ChatQuestionAsked;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool as ToolContract;
use Laravel\Ai\Tools\Request as AiRequest;
use Stringable;

/**
 * A chat-scoped tool that lets the plain assistant ask the user a clarifying
 * multiple-choice question — an {@see ChatMessage} with `message_type=question`
 * — rendered as a card with one clickable button per option (plus an optional
 * free-text "Other"). Calling it ENDS the current turn: the model cannot see the
 * answer inline. When the user picks an option, that selection is posted as a
 * normal user turn, so the model naturally resumes with the choice in history.
 * This is the interactive counterpart to writing the question in prose, for when
 * the assistant needs a bounded decision from the user before it can continue.
 * The question is surfaced live via {@see ChatQuestionAsked}.
 */
class AskUserQuestionTool implements ToolContract
{
    /** Hard caps that keep the card readable and the payload small. */
    private const MAX_OPTIONS = 6;

    private const MAX_LABEL = 80;

    private const MAX_DESCRIPTION = 160;

    public function __construct(
        private Chat $chat,
        private User $user,
    ) {}

    public function description(): Stringable|string
    {
        return 'Ask the user a single clarifying question with a small set of predefined choices, shown as clickable option buttons. Use this instead of writing the question in prose WHEN you genuinely need the user to pick between a few bounded options before you can proceed (e.g. which of two accounts, which format, confirm vs cancel). Calling this ENDS your turn — you will NOT see the answer in this reply; the user\'s pick arrives as their next message and you continue then. Do NOT use it for open-ended questions, for things you can reasonably decide yourself, or to stall — only when a short menu of choices unblocks you. Provide 2-6 concise options; keep an "Other" free-text escape hatch on unless the choices are exhaustive.';
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'question' => $schema->string()->description('The question to ask, in the user\'s language. One clear sentence.')->required(),
            'options' => $schema->array()
                ->description('The choices, as an array of objects: [{"label": string (required, the button text, max 80 chars), "description"?: string (optional one-line clarification, max 160 chars)}]. Provide 2-6 distinct, mutually exclusive options.')
                ->required(),
            'allow_other' => $schema->boolean()->description('Whether to offer a free-text "Other" field so the user can answer outside the listed options. Default true; set false only when the options are exhaustive.'),
        ];
    }

    public function handle(AiRequest $request): Stringable|string
    {
        $args = $request->toArray();

        $question = trim((string) ($args['question'] ?? ''));
        if ($question === '') {
            return 'Error: provide a non-empty question.';
        }

        $options = $this->normalizeOptions($args['options'] ?? []);
        if (count($options) < 2) {
            return 'Error: provide at least 2 options, each with a non-empty label.';
        }

        $payload = [
            'question' => Str::limit($question, 400, ''),
            'options' => $options,
            // Default the escape hatch on unless explicitly disabled.
            'allow_other' => ! array_key_exists('allow_other', $args) || (bool) $args['allow_other'],
            // Per-message lifecycle: a chat can hold several questions, so the
            // card's answered/locked state and the chosen value live here.
            'selected' => null,
            'status' => 'pending',
        ];

        $message = ChatMessage::create([
            'chat_id' => $this->chat->id,
            'role' => 'assistant',
            'content' => $payload['question'],
            'status' => 'complete',
            'message_type' => 'question',
            'action_payload' => $payload,
        ]);

        try {
            ChatQuestionAsked::dispatch($message);
        } catch (\Throwable) {
            // Live surfacing is best-effort; the card still loads on reload.
        }

        return 'Asked the user: "'.$payload['question'].'". Wait for their selection — it will arrive as their next message. Do not answer the question yourself or restate the options now.';
    }

    /**
     * Coerce the model's `options` argument into a clean list of
     * {label, description} shapes, dropping blanks and enforcing caps.
     *
     * @return list<array{label: string, description: string|null}>
     */
    private function normalizeOptions(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $options = [];
        foreach ($raw as $entry) {
            $label = '';
            $description = null;

            if (is_array($entry)) {
                $label = trim((string) ($entry['label'] ?? ''));
                $rawDescription = trim((string) ($entry['description'] ?? ''));
                $description = $rawDescription !== '' ? Str::limit($rawDescription, self::MAX_DESCRIPTION, '') : null;
            } else {
                // Tolerate a plain array of strings.
                $label = trim((string) $entry);
            }

            if ($label === '') {
                continue;
            }

            $options[] = [
                'label' => Str::limit($label, self::MAX_LABEL, ''),
                'description' => $description,
            ];

            if (count($options) >= self::MAX_OPTIONS) {
                break;
            }
        }

        return $options;
    }
}
