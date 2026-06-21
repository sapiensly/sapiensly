<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Emitted while the assistant consults another agent mid-turn, so the UI can
 * show it live: a "Consulting <Agent>…" indicator on `start`, then the answer on
 * `result`. `visible` is the consulting agent's choice — false renders a compact
 * pill (background), true a full card showing the other agent's answer (front).
 * The completed consultation is also persisted on the message's
 * agent_data_context so it survives a reload.
 */
class ChatAgentConsultation implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  'start'|'result'  $phase
     */
    public function __construct(
        public string $chatId,
        public string $messageId,
        public string $phase,
        public string $consultationId,
        public string $agentId,
        public string $agentName,
        public string $question,
        public bool $visible,
        public ?string $answer = null,
    ) {}

    public function broadcastAs(): string
    {
        return 'ChatAgentConsultation';
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.conversation.{$this->chatId}")];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->messageId,
            'phase' => $this->phase,
            'consultation_id' => $this->consultationId,
            'agent_id' => $this->agentId,
            'agent_name' => $this->agentName,
            'question' => $this->question,
            'visible' => $this->visible,
            'answer' => $this->answer,
        ];
    }
}
