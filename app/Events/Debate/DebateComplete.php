<?php

namespace App\Events\Debate;

use App\Models\Debate;
use App\Services\Debate\DebatePresenter;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when the synthesis finishes. Carries the full debate (participants with
 * their final stance + the synthesis round) so the UI reveals the Conclusions
 * panel.
 */
class DebateComplete implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Debate $debate) {}

    public function broadcastAs(): string
    {
        return 'DebateComplete';
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("debate.{$this->debate->id}")];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['debate' => DebatePresenter::debate(
            $this->debate->load(['participants', 'rounds.turns'])
        )];
    }
}
