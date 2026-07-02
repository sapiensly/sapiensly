<?php

namespace App\Events\Slides;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The Slide Builder assistant finished a turn. When the turn edited the deck,
 * `manifest` (raw, for the inspector) and `resolved` (live bindings resolved,
 * for the preview) carry the fresh state so the UI refreshes in place.
 */
class SlideBuilderComplete implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>|null  $manifest
     * @param  array<string, mixed>|null  $resolved
     */
    public function __construct(
        public string $documentId,
        public string $messageId,
        public string $content,
        public ?array $manifest = null,
        public ?array $resolved = null,
        public ?string $name = null,
    ) {}

    public function broadcastAs(): string
    {
        return 'SlideBuilderComplete';
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
            'content' => $this->content,
            'manifest' => $this->manifest,
            'resolved' => $this->resolved,
            'name' => $this->name,
        ];
    }
}
