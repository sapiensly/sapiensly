<?php

namespace App\Services\Chat;

use App\Events\Chat\ChatActionExecuted;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Services\Chat\Actions\ActionRegistry;
use App\Services\Chat\Actions\ManualAction;
use Illuminate\Support\Facades\Log;
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
     */
    public function execute(Chat $chat, ChatMessage $proposal): ChatMessage
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

        return $message;
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
