<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DocumentStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $knowledgeBaseId,
        public string $documentId,
        public string $status,
        public ?string $errorMessage = null,
        public ?string $knowledgeBaseStatus = null,
        public ?int $documentCount = null,
        public ?int $chunkCount = null,
    ) {}

    public function broadcastAs(): string
    {
        return 'DocumentStatusChanged';
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("knowledge-base.{$this->knowledgeBaseId}"),
        ];
    }
}
