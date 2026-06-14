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

        if ($chat->synthesis_status === 'executed') {
            throw new RuntimeException('This action has already been executed.');
        }

        $payload = (array) ($proposal->action_payload ?? []);
        $actionType = (string) ($payload['action_type'] ?? ManualAction::KEY);

        $handler = $this->registry->resolve($actionType);
        $result = $handler->execute($chat, $payload);

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

        $chat->forceFill([
            'synthesis_status' => 'executed',
            'last_message_at' => now(),
        ])->save();

        try {
            ChatActionExecuted::dispatch($message, 'executed');
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

        $chat->forceFill(['synthesis_status' => 'dismissed'])->save();

        try {
            ChatActionExecuted::dispatch($proposal, 'dismissed');
        } catch (\Throwable $e) {
            Log::warning('Chat action dismissed broadcast failed (continuing)', ['error' => $e->getMessage()]);
        }
    }
}
