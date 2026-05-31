<?php

namespace App\Events\Debate;

use App\Models\Debate;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Lightweight header update: debate status, round counter, and latest
 * consensus score — drives the status badge and consensus meter.
 */
class DebateStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Debate $debate) {}

    public function broadcastAs(): string
    {
        return 'DebateStatusChanged';
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
        return [
            'status' => $this->debate->status,
            'current_round' => $this->debate->current_round,
            'max_rounds' => $this->debate->max_rounds,
            'consensus_reached' => $this->debate->consensus_reached,
            'consensus_score' => $this->debate->consensus_score,
        ];
    }
}
