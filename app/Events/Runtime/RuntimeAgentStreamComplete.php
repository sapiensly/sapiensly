<?php

namespace App\Events\Runtime;

use App\Models\RuntimeAgentMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when the runtime agent is done streaming a turn (builder power #3).
 * Carries the final assistant message so the client can replace its placeholder.
 */
class RuntimeAgentStreamComplete implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public RuntimeAgentMessage $message) {}

    public function broadcastAs(): string
    {
        return 'RuntimeAgentStreamComplete';
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("runtime.agent.conversation.{$this->message->conversation_id}")];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'message' => [
                'id' => $this->message->id,
                'role' => $this->message->role,
                'content' => $this->message->content,
                'message_type' => $this->message->message_type,
                'action_payload' => $this->message->action_payload,
                'status' => $this->message->status,
                'created_at' => $this->message->created_at?->toIso8601String(),
            ],
        ];
    }
}
