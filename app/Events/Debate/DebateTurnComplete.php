<?php

namespace App\Events\Debate;

use App\Models\DebateTurn;
use App\Services\Debate\DebatePresenter;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a turn finishes streaming. Carries the final turn so the client
 * can replace its streaming card (content + parsed stance).
 */
class DebateTurnComplete implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public DebateTurn $turn) {}

    public function broadcastAs(): string
    {
        return 'DebateTurnComplete';
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("debate.{$this->turn->debate_id}")];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['turn' => DebatePresenter::turn($this->turn)];
    }
}
