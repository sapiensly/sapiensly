<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A single text delta coming off an LLM stream for a document generation
 * or refinement. The frontend accumulates these into the artifact body
 * until DocumentStreamComplete fires.
 */
class DocumentStreamChunk implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $streamId,
        public string $content,
    ) {}

    public function broadcastAs(): string
    {
        return 'DocumentStreamChunk';
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
        return [
            'content' => $this->content,
        ];
    }
}
