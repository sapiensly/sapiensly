<?php

namespace App\Events\Builder;

use App\Models\BuilderMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when Claude is done streaming. Carries the final assistant message
 * (with proposed_patch if any) so the client can replace its placeholder.
 */
class BuilderStreamComplete implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public BuilderMessage $message) {}

    public function broadcastAs(): string
    {
        return 'BuilderStreamComplete';
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("builder.conversation.{$this->message->conversation_id}")];
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
                'proposed_patch' => $this->message->proposed_patch,
                'change_summary' => $this->message->change_summary,
                'status' => $this->message->status,
                'applied_version_id' => $this->message->applied_version_id,
                'created_at' => $this->message->created_at?->toIso8601String(),
            ],
        ];
    }
}
