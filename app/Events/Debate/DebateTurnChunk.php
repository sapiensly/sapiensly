<?php

namespace App\Events\Debate;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Incremental piece of a participant's (or the moderator's) argument as the
 * model streams it. Broadcast to `debate.{id}` — the UI appends to the matching
 * turn card until DebateTurnComplete fires.
 */
class DebateTurnChunk implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $debateId,
        public string $turnId,
        public string $delta,
    ) {}

    public function broadcastAs(): string
    {
        return 'DebateTurnChunk';
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("debate.{$this->debateId}")];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'turn_id' => $this->turnId,
            'delta' => $this->delta,
        ];
    }
}
