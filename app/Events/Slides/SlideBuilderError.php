<?php

namespace App\Events\Slides;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A Slide Builder turn failed — the UI unfreezes the composer and shows the
 * reason on the placeholder message.
 */
class SlideBuilderError implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $documentId,
        public string $messageId,
        public string $message,
    ) {}

    public function broadcastAs(): string
    {
        return 'SlideBuilderError';
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
            'message' => $this->message,
        ];
    }
}
