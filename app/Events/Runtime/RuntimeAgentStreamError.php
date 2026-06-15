<?php

namespace App\Events\Runtime;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a runtime agent turn fails (builder power #3) so the client can
 * stop the spinner and show the error. The DB status is the source of truth on
 * the next load if the broadcast itself is lost.
 */
class RuntimeAgentStreamError implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $conversationId,
        public string $messageId,
        public string $error,
    ) {}

    public function broadcastAs(): string
    {
        return 'RuntimeAgentStreamError';
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
            'error' => $this->error,
        ];
    }
}
