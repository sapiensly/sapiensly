<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Signals that an LLM stream for a document generation/refinement has
 * finished cleanly. The event carries no body — deliberately — because
 * Reverb / Pusher cap individual payloads (10 KB by default) and the
 * accumulated DocumentStreamChunk deltas already give the frontend the
 * full text in order. Shipping the whole artifact HTML here caused
 * "Payload too large" failures on non-trivial generations.
 */
class DocumentStreamComplete implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $streamId,
    ) {}

    public function broadcastAs(): string
    {
        return 'DocumentStreamComplete';
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("documents.stream.{$this->streamId}"),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [];
    }
}
