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
 * An ActionCard was acted on. For an execution, the message is the new
 * action_result row (synthesis_status `executed`); for a dismissal it is the
 * original proposal (status `dismissed`) and the frontend collapses the card.
 */
class ChatActionExecuted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ChatMessage $message, public string $synthesisStatus) {}

    public function broadcastAs(): string
    {
        return 'ChatActionExecuted';
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
