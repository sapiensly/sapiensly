<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Emitted across a tool's lifecycle mid-stream so the UI can show live progress:
 * a "using <tool>…" chip on `start`, then a done/failed chip on `result`. The
 * `tool_id` (the provider's tool-call id) correlates the two so a turn with
 * several tool calls tracks each independently. Not persisted — informational.
 */
class ChatToolCall implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  'start'|'result'  $phase
     */
    public function __construct(
        public string $chatId,
        public string $messageId,
        public string $toolName,
        public string $phase = 'start',
        public string $toolId = '',
        public ?bool $successful = null,
    ) {}

    public function broadcastAs(): string
    {
        return 'ChatToolCall';
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.conversation.{$this->chatId}")];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->messageId,
            'tool_name' => $this->toolName,
            'phase' => $this->phase,
            'tool_id' => $this->toolId,
            'successful' => $this->successful,
        ];
    }
}
