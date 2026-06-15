<?php

namespace App\Events\Builder;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Live progress signal for a Builder turn — what the model is doing right now,
 * so the UI can give constant, legible feedback instead of an opaque spinner.
 * Broadcast on `builder.conversation.{id}` as the turn runs; informational only
 * (not persisted). Phases:
 *   - "thinking"  → the model started (carries the resolved model id)
 *   - "tool"      → the model is calling a tool (carries the tool name)
 *   - "writing"   → the model is composing its reply text
 *   - "applying"  → a proposed change is being applied
 */
class BuilderActivity implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $conversationId,
        public string $messageId,
        public string $phase,
        public ?string $model = null,
        public ?string $tool = null,
    ) {}

    public function broadcastAs(): string
    {
        return 'BuilderActivity';
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
            'phase' => $this->phase,
            'model' => $this->model,
            'tool' => $this->tool,
        ];
    }
}
