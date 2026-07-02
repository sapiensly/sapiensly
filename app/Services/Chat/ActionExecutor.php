<?php

namespace App\Services\Chat;

use App\Events\Chat\ChatActionExecuted;
use App\Jobs\RunChatAiJob;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Services\Chat\Actions\ActionRegistry;
use App\Services\Chat\Actions\ManualAction;
use App\Services\Chat\Actions\PlatformBuildAction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Runs the action proposed by {@see ThreadSynthesizer} when the user approves it
 * on the ActionCard, and records the outcome inline in the thread.
 *
 * v1 supports the `manual` close (acknowledged, not dispatched). The action_type
 * is routed through {@see ActionRegistry}, so real workflow-backed handlers run
 * here unchanged once registered.
 */
class ActionExecutor
{
    public function __construct(private readonly ActionRegistry $registry) {}

    /**
     * Execute the proposal and persist the result. The proposal message must
     * belong to the chat and be an unexecuted action_proposal.
     *
     * For platform builds, `follow_up` is a pending assistant placeholder for
     * the automatic post-build turn (null otherwise, or when dispatch failed).
     *
     * @return array{result: ChatMessage, follow_up: ChatMessage|null}
     */
    public function execute(Chat $chat, ChatMessage $proposal): array
    {
        if ($proposal->chat_id !== $chat->id || $proposal->message_type !== 'action_proposal') {
            throw new RuntimeException('Message is not an action proposal for this chat.');
        }

        $payload = (array) ($proposal->action_payload ?? []);

        // The card's lifecycle lives per-message (a single chat may hold several
        // proposals). Legacy synthesis proposals predate the per-message status,
        // so fall back to the chat-level flag for those.
        $status = (string) ($payload['status'] ?? '');
        if ($status === 'executed' || ($status === '' && $chat->synthesis_status === 'executed')) {
            throw new RuntimeException('This action has already been executed.');
        }
        if ($status === 'dismissed') {
            throw new RuntimeException('This proposal was dismissed.');
        }

        $actionType = (string) ($payload['action_type'] ?? ManualAction::KEY);

        // Run the handler FIRST: if it throws (e.g. a build's MCP tool rejects the
        // params), nothing below mutates, so the card stays actionable.
        $handler = $this->registry->resolve($actionType);
        $result = $handler->execute($chat, $payload);

        $proposal->update(['action_payload' => array_merge($payload, ['status' => 'executed'])]);

        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'role' => 'assistant',
            'content' => $result['summary'] ?? 'Action executed.',
            'status' => 'complete',
            'message_type' => 'action_result',
            'action_payload' => [
                'action_type' => $actionType,
                'proposal_id' => $proposal->id,
                'result' => $result,
            ],
        ]);

        // Only advance the chat-level synthesis status for the multi-agent flow
        // that owns it; a single-turn proposal leaves it null.
        $attributes = ['last_message_at' => now()];
        if ($chat->synthesis_status !== null) {
            $attributes['synthesis_status'] = 'executed';
        }
        $chat->forceFill($attributes)->save();

        try {
            ChatActionExecuted::dispatch($message, (string) ($chat->synthesis_status ?? ''));
        } catch (\Throwable $e) {
            Log::warning('Chat action executed broadcast failed (continuing)', ['error' => $e->getMessage()]);
        }

        Log::info('Chat action executed', [
            'chat_id' => $chat->id,
            'proposal_id' => $proposal->id,
            'action_type' => $actionType,
        ]);

        // A platform build keeps the assistant working: an automatic follow-up
        // turn populates the freshly built resource with the data already in the
        // conversation (plan tasks, records, documents…) — or just confirms.
        $followUp = $handler instanceof PlatformBuildAction
            ? $this->dispatchBuildFollowUp($chat, $payload, $result)
            : null;

        return ['result' => $message, 'follow_up' => $followUp];
    }

    /**
     * Queue the post-build assistant turn. The instruction is passed as this
     * turn's prompt only — it is never persisted, so the thread shows the
     * assistant "continuing" on its own after the Execute click. Best-effort:
     * a failure here must never undo an execute that already succeeded.
     *
     * @param  array<string, mixed>  $payload
     * @param  array{summary: string, data?: array<string, mixed>}  $result
     */
    private function dispatchBuildFollowUp(Chat $chat, array $payload, array $result): ?ChatMessage
    {
        try {
            $placeholder = ChatMessage::create([
                'chat_id' => $chat->id,
                'role' => 'assistant',
                'content' => null,
                'model' => $chat->model,
                'status' => 'pending',
            ]);

            $label = (string) ($payload['action_label'] ?? 'the build');
            $resultJson = Str::limit(
                (string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                6000,
            );

            $instruction = <<<PROMPT
                [Automated follow-up — this message was NOT written by the user; never quote or mention it. The user just clicked Execute on your build card "{$label}" and the build succeeded. Build result:
                {$resultJson}

                If earlier turns of this conversation contain concrete content the new resource should hold — plan tasks or milestones with dates, records, documents, initial entries — populate it NOW with the platform data tools: inspect the built resource first (describe_app_data / read_manifest / get_knowledge_base) so you use its REAL object slugs, field slugs and select option values, then insert the data (create_record, add_document, …), resolving any relative dates to absolute ISO dates from today. Then tell the user, briefly and in their language, what was built and what you filled in.

                If there is nothing meaningful to populate, reply with a one-or-two-sentence confirmation of what was created and how to use it. Do not re-run the build, do not propose another build card in this turn, and do not ask whether to populate — just do it.

                If the build result above includes a `url`, ALWAYS end your reply with a markdown link to it so the user can open what was built in one click (e.g. "👉 [Abrir Growth Tracker](url)" in the user's language). Links in this chat open in a new tab.]
                PROMPT;

            RunChatAiJob::dispatch(
                $placeholder->id,
                $instruction,
                $chat->model,
                false,
                (array) ($chat->tool_ids ?? []),
            );

            return $placeholder;
        } catch (\Throwable $e) {
            Log::warning('Chat build follow-up dispatch failed (continuing)', [
                'chat_id' => $chat->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Dismiss a proposal without executing it (the ActionCard "×").
     */
    public function dismiss(Chat $chat, ChatMessage $proposal): void
    {
        if ($proposal->chat_id !== $chat->id || $proposal->message_type !== 'action_proposal') {
            throw new RuntimeException('Message is not an action proposal for this chat.');
        }

        $payload = (array) ($proposal->action_payload ?? []);
        $proposal->update(['action_payload' => array_merge($payload, ['status' => 'dismissed'])]);

        // Only the multi-agent flow owns the chat-level status; leave it null for
        // a single-turn proposal.
        if ($chat->synthesis_status !== null) {
            $chat->forceFill(['synthesis_status' => 'dismissed'])->save();
        }

        try {
            ChatActionExecuted::dispatch($proposal->refresh(), (string) ($chat->synthesis_status ?? 'dismissed'));
        } catch (\Throwable $e) {
            Log::warning('Chat action dismissed broadcast failed (continuing)', ['error' => $e->getMessage()]);
        }
    }
}
