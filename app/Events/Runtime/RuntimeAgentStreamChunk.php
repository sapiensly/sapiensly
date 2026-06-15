<?php

namespace App\Events\Runtime;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Incremental piece of the runtime agent's reply as it streams (builder power
 * #3). Broadcast to `runtime.agent.conversation.{id}` — the runtime UI appends
 * to the placeholder message until RuntimeAgentStreamComplete fires.
 */
class RuntimeAgentStreamChunk implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $conversationId,
        public string $messageId,
        public string $delta,
    ) {}

    public function broadcastAs(): string
    {
        return 'RuntimeAgentStreamChunk';
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("runtime.agent.conversation.{$this->conversationId}")];
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
