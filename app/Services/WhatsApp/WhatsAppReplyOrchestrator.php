<?php

namespace App\Services\WhatsApp;

use App\Enums\MessageRole;
use App\Models\Agent;
use App\Models\AgentTeam;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsAppConversation;
use App\Services\LLMService;
use App\Services\TeamOrchestrationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Bridges a WhatsApp conversation into the existing Agent / AgentTeam / Flow
 * orchestration layer. The contract with the rest of the system is intentionally
 * identical to the widget's SSE path: build a synthetic `Conversation` with a
 * messages relation, drive it through `LLMService` or `TeamOrchestrationService`,
 * then persist the assistant reply via `WhatsAppMessageSender`.
 *
 * Flow state continuity is preserved across turns by copying
 * `$wa->flow_state ↔ synth->metadata['flow_state']` before and after the
 * generator runs.
 */
class WhatsAppReplyOrchestrator
{
    /**
     * Hard cap for the context window we hand to the LLM. Matches the widget's
     * implicit behaviour (messages() relation on the conversation is all rows)
     * but clipped here to avoid unbounded growth on chatty threads.
     */
    private const CONTEXT_WINDOW = 30;

    public function __construct(
        private LLMService $llmService,
        private TeamOrchestrationService $orchestrationService,
        private WhatsAppMessageSender $sender,
    ) {}

    public function reply(WhatsAppConversation $conversation): void
    {
        $conversation->loadMissing('channel.agent', 'channel.agentTeam');
        $channel = $conversation->channel;

        if ($channel === null) {
            return;
        }

        $target = $channel->getTarget();
        if ($target === null) {
            Log::channel('whatsapp')->warning('orchestrator.no_target', [
                'conversation_id' => $conversation->id,
                'channel_id' => $channel->id,
            ]);

            return;
        }

        $messages = $this->loadContextMessages($conversation);
        if ($messages->isEmpty()) {
            return;
        }

        $userMessage = (string) $messages->last()->content;

        $synthetic = $this->buildSyntheticConversation($conversation, $messages);

        try {
            if ($target instanceof AgentTeam) {
                $reply = $this->runTeam($target, $synthetic, $userMessage);
            } else {
                $reply = $this->runAgent($target, $messages);
            }
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->error('orchestrator.failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $this->persistFlowState($conversation, $synthetic);

        if ($reply !== '') {
            $this->sender->sendText($conversation->fresh(), $reply);
        }
    }

    private function loadContextMessages(WhatsAppConversation $conversation): Collection
    {
        return $conversation->messages()
            ->orderByDesc('created_at')
            ->limit(self::CONTEXT_WINDOW)
            ->get()
            ->sortBy('created_at')
            ->values();
    }

    /**
     * Build a `Conversation` model that mirrors the WhatsApp thread but never
     * hits the database. The anonymous subclass ensures any `update()` calls
     * made inside the flow executor (which persists flow_state back onto the
     * conversation) are captured in-memory and replayed onto the real
     * WhatsApp conversation afterwards.
     */
    private function buildSyntheticConversation(WhatsAppConversation $conversation, Collection $waMessages): Conversation
    {
        $synth = new class(['id' => $conversation->id]) extends Conversation
        {
            public $exists = true;

            public $timestamps = false;

            public function save(array $options = []): bool
            {
                return true;
            }

            public function update(array $attributes = [], array $options = []): bool
            {
                foreach ($attributes as $key => $value) {
                    $this->setAttribute($key, $value);
                }

                return true;
            }
        };

        $synth->metadata = [
            'flow_state' => $conversation->flow_state,
        ];

        $synth->setRelation('messages', $waMessages->map(fn ($waMsg) => new Message([
            'id' => $waMsg->id,
            'role' => $waMsg->role instanceof MessageRole ? $waMsg->role : MessageRole::from((string) $waMsg->role),
            'content' => (string) $waMsg->content,
        ])));

        return $synth;
    }

    private function runAgent(Agent $agent, Collection $messages): string
    {
        $messageModels = $messages->map(fn ($waMsg) => new Message([
            'role' => $waMsg->role instanceof MessageRole ? $waMsg->role : MessageRole::from((string) $waMsg->role),
            'content' => (string) $waMsg->content,
        ]))->all();

        if ($agent->tools()->where('status', 'active')->exists()) {
            $response = $this->llmService->chatWithTools($agent, $messageModels);

            return (string) ($response->text ?? '');
        }

        return $this->llmService->chat($agent, $messageModels);
    }

    private function runTeam(AgentTeam $team, Conversation $synthetic, string $userMessage): string
    {
        $reply = '';

        foreach ($this->orchestrationService->orchestrate($team, $synthetic, $userMessage) as $event) {
            match ($event['type'] ?? null) {
                'content' => $reply .= (string) ($event['content'] ?? ''),
                'flow_message' => $reply .= (string) ($event['content'] ?? ''),
                'flow_menu' => $reply = $this->formatFlowMenu($event, $reply),
                default => null,
            };
        }

        return trim($reply);
    }

    /**
     * Flow menus arrive as { message, options: [{label, value}, …] }. Render them
     * as "<message>\n\n1. <label>\n2. <label>…" which is the canonical plain-text
     * fallback for non-interactive channels.
     */
    private function formatFlowMenu(array $event, string $accumulated): string
    {
        $base = $accumulated === '' ? '' : $accumulated."\n\n";
        $message = (string) ($event['message'] ?? '');
        $options = $event['options'] ?? [];

        $lines = [];
        foreach ($options as $index => $option) {
            $label = is_array($option) ? ($option['label'] ?? '') : (string) $option;
            if ($label !== '') {
                $lines[] = ($index + 1).'. '.$label;
            }
        }

        $rendered = $message;
        if (! empty($lines)) {
            $rendered = trim($message."\n\n".implode("\n", $lines));
        }

        return $base.$rendered;
    }

    private function persistFlowState(WhatsAppConversation $conversation, Conversation $synthetic): void
    {
        $newFlowState = $synthetic->metadata['flow_state'] ?? null;

        if ($newFlowState === $conversation->flow_state) {
            return;
        }

        $conversation->forceFill(['flow_state' => $newFlowState])->save();
    }
}
