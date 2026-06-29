<?php

namespace App\Events\Builder;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a turn is enqueued WITHOUT an originating HTTP request — i.e. an
 * autonomous-mode continuation, where the server creates the next user turn +
 * assistant placeholder itself. Carries those fresh message DTOs so the client
 * can append them immediately and stream the placeholder live (the normal send
 * path gets them in the POST response instead).
 */
class BuilderTurnQueued implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  list<array<string, mixed>>  $messages  fresh message DTOs to append
     */
    public function __construct(public string $conversationId, public array $messages) {}

    public function broadcastAs(): string
    {
        return 'BuilderTurnQueued';
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
        return ['messages' => $this->messages];
    }
}
