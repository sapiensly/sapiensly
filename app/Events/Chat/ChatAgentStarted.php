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
 * A mentioned agent's turn has begun: its placeholder message exists (status
 * pending). The frontend materializes the agent bubble with a typing indicator
 * before the first token arrives.
 */
class ChatAgentStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ChatMessage $message) {}

    public function broadcastAs(): string
    {
        return 'ChatAgentStarted';
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
            'message' => ChatMessagePresenter::present($this->message),
        ];
    }
}
