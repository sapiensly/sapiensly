<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AgentStreamChunk implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $conversationId,
        public string $content,
        public ?string $type = null,
        public ?array $metadata = null,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastAs(): string
    {
        return 'AgentStreamChunk';
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("conversation.{$this->conversationId}"),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $data = [];

        if ($this->type) {
            $data['type'] = $this->type;
        }

        if ($this->content !== '') {
            $data['content'] = $this->content;
        }

        if ($this->metadata) {
            $data = array_merge($data, $this->metadata);
        }

        return $data;
    }
}
