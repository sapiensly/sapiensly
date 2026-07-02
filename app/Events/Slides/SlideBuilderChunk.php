<?php

namespace App\Events\Slides;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Incremental piece of the Slide Builder assistant's reply as it streams.
 * Broadcast to `slides.builder.{documentId}` — the Builder UI appends to the
 * placeholder message until SlideBuilderComplete fires.
 */
class SlideBuilderChunk implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $documentId,
        public string $messageId,
        public string $delta,
    ) {}

    public function broadcastAs(): string
    {
        return 'SlideBuilderChunk';
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("slides.builder.{$this->documentId}")];
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
