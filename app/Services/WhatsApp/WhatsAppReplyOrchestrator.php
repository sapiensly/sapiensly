<?php

namespace App\Services\WhatsApp;

use App\Enums\MessageRole;
use App\Models\Agent;
use App\Models\BotFlow;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\ConversationAttachmentService;
use App\Services\LLMService;
use App\Services\Storage\TenantStorage;
use App\Services\TeamOrchestrationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Bridges a WhatsApp conversation into the connection's Bot Flow
 * orchestration layer. The contract with the rest of the system is intentionally
 * identical to the widget's SSE path: build a synthetic `Conversation` with a
 * messages relation, drive it through `LLMService` or `TeamOrchestrationService`,
 * then persist the assistant reply via `WhatsAppMessageSender`.
 *
 * BotFlow state continuity is preserved across turns by copying
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
        private ConversationAttachmentService $attachments,
        private TenantStorage $tenantStorage,
    ) {}

    public function reply(WhatsAppConversation $conversation): void
    {
        $conversation->loadMissing('channel.whatsAppConnection.botFlow');
        $channel = $conversation->channel;

        if ($channel === null) {
            return;
        }

        // The WhatsApp bot runs on its connection's Bot Flow roster.
        $flow = $channel->whatsAppConnection?->botFlow;
        $roster = $flow?->rosterAgents() ?? [];

        if ($roster === []) {
            Log::channel('whatsapp')->warning('orchestrator.no_agents', [
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

        // Files the contact sent this turn (a PDF/image on WhatsApp) — documents
        // are surfaced to the bot via their extracted text.
        $attachments = $this->turnAttachments($messages, $channel->organization_id);

        try {
            $reply = count($roster) === 1
                ? $this->runAgent($roster[0], $messages, $attachments)
                : $this->runBotFlow($flow, $synthetic, $userMessage, $attachments);
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

    /**
     * @param  array<int, array<string, mixed>>  $attachments
     */
    private function runAgent(Agent $agent, Collection $messages, array $attachments = []): string
    {
        $models = $messages->map(fn ($waMsg) => new Message([
            'role' => $waMsg->role instanceof MessageRole ? $waMsg->role : MessageRole::from((string) $waMsg->role),
            'content' => (string) $waMsg->content,
        ]))->all();

        // Fold any document text into the last user message so a single agent
        // can read it without depending on the SDK's document support.
        $documentContext = $this->attachments->documentContext($attachments);
        if ($documentContext !== '' && $models !== []) {
            $last = $models[count($models) - 1];
            $last->content = (string) $last->content."\n\n".$documentContext;
        }

        $images = [];
        foreach ($attachments as $descriptor) {
            if (($descriptor['kind'] ?? null) === 'image' && ! empty($descriptor['disk'])) {
                $images[] = $this->attachments->toStoredFile($descriptor);
            }
        }

        if ($agent->tools()->where('status', 'active')->exists()) {
            $response = $this->llmService->chatWithTools($agent, $models, attachments: $images);

            return (string) ($response->text ?? '');
        }

        return $this->llmService->chat($agent, $models, $images);
    }

    /**
     * Build normalized descriptors for the file the contact sent this turn.
     * Documents carry their extracted text (no disk needed); images need a
     * resolvable disk for vision, which we attach best-effort.
     *
     * @param  Collection<int, WhatsAppMessage>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function turnAttachments(Collection $messages, ?string $organizationId): array
    {
        $last = $messages->last();
        if ($last === null || ! $last->media_mime || ! $last->media_local_path) {
            return [];
        }

        $mime = (string) $last->media_mime;
        $disk = '';
        if ($this->attachments->kindForMime($mime) === 'image') {
            try {
                $disk = $this->tenantStorage->diskNameForOwner($organizationId, null);
            } catch (\Throwable) {
                $disk = '';
            }
        }

        return [
            $this->attachments->descriptor(
                (string) $last->id,
                basename((string) $last->media_local_path),
                $mime,
                $disk,
                (string) $last->media_local_path,
                $last->metadata['extracted_text'] ?? null,
            ),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $attachments
     */
    private function runBotFlow(BotFlow $flow, Conversation $synthetic, string $userMessage, array $attachments = []): string
    {
        $reply = '';

        foreach ($this->orchestrationService->orchestrateBotFlow($flow, $synthetic, $userMessage, $attachments) as $event) {
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
     * BotFlow menus arrive as { message, options: [{label, value}, …] }. Render them
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
