<?php

namespace App\Events\Chat;

use App\Models\ChatMessage;
use App\Support\Chat\ChatMessagePresenter;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Synthesis finished. Carries either the action_proposal message (the ActionCard)
 * with synthesis_status `ready`, or the "no clear recommendation" system message
 * with status `dismissed`.
 */
class ChatActionProposalReady implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ChatMessage $message, public string $synthesisStatus) {}

    public function broadcastAs(): string
    {
        return 'ChatActionProposalReady';
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.conversation.{$this->message->chat_id}")];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'chat_id' => $this->message->chat_id,
            'synthesis_status' => $this->synthesisStatus,
            'message' => ChatMessagePresenter::present($this->message),
        ];
    }
}
