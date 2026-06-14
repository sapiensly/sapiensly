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
 * Fired when the model is done streaming. Carries the final assistant message
 * so the client can replace its streaming placeholder.
 */
class ChatStreamComplete implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ChatMessage $message, public ?string $chatTitle = null) {}

    public function broadcastAs(): string
    {
        return 'ChatStreamComplete';
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
            'title' => $this->chatTitle,
            'message' => ChatMessagePresenter::present($this->message),
        ];
    }
}
