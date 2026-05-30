<?php

namespace App\Events\Builder;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Incremental piece of the assistant's reply as Claude streams it.
 * Broadcast to `builder.conversation.{id}` — the Builder UI appends to
 * the placeholder message until BuilderStreamComplete fires.
 */
class BuilderStreamChunk implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $conversationId,
        public string $messageId,
        public string $delta,
    ) {}

    public function broadcastAs(): string
    {
        return 'BuilderStreamChunk';
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("builder.conversation.{$this->conversationId}")];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->messageId,
            'delta' => $this->delta,
        ];
    }
}
