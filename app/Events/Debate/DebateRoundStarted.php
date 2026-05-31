<?php

namespace App\Events\Debate;

use App\Models\DebateRound;
use App\Services\Debate\DebatePresenter;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Announces a new round with its pending turn cards so the UI can reveal the
 * empty cards that are about to start streaming.
 */
class DebateRoundStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public DebateRound $round) {}

    public function broadcastAs(): string
    {
        return 'DebateRoundStarted';
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("debate.{$this->round->debate_id}")];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['round' => DebatePresenter::round($this->round->load('turns'))];
    }
}
