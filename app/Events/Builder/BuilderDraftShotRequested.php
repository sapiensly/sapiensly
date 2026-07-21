<?php

namespace App\Events\Builder;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Stage 2 of the design director's eyes: mid-turn, the critique asks the OPEN
 * builder UI to render the current DRAFT off-screen and send back a screenshot,
 * so the director judges the pixels of what was just authored — not the last
 * applied version. The event carries only a nonce (broadcast payloads are
 * size-capped); the UI fetches the draft payload over HTTP, which doubles as
 * the "a browser is listening" ack. No browser → the critique degrades to the
 * applied-version shot or text-only, exactly like Stage 1.
 */
class BuilderDraftShotRequested implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $conversationId,
        public string $nonce,
    ) {}

    public function broadcastAs(): string
    {
        return 'BuilderDraftShotRequested';
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
        return ['nonce' => $this->nonce];
    }
}
