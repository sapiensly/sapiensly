<?php

namespace App\Services\WhatsApp;

use App\Enums\ChannelStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Enums\WhatsAppContentType;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * High-level outbound API used by the orchestrator, the human-takeover inbox,
 * and any future caller. Persists the WhatsAppMessage up front (so UI reflects
 * intent immediately) and dispatches SendWhatsAppMessageJob — actual provider
 * calls happen in the queue layer.
 *
 * Compliance gates applied before queueing:
 *   - Channel status must be Active.
 *   - Contact must not be opted out.
 *   - Template messages bypass the 24-hour "session window"; free-form text
 *     messages outside the window are rejected with a MessageStatus::Failed
 *     row instead of being sent.
 */
class WhatsAppMessageSender
{
    public const MAX_TEXT_CHUNK = 4096; // Meta hard cap per message.

    /**
     * Queue a free-form text reply. Returns the persisted WhatsAppMessage row(s)
     * (plural when the text is chunked). Throws WhatsAppSendException when a
     * compliance gate blocks the send.
     *
     * @return array<int, WhatsAppMessage>
     */
    public function sendText(
        WhatsAppConversation $conversation,
        string $text,
        ?User $sender = null,
        ?MessageRole $role = null,
    ): array {
        $this->assertCanSend($conversation, isTemplate: false);

        $chunks = $this->chunkText($text);
        $groupId = count($chunks) > 1 ? (string) Str::ulid() : null;
        $role ??= MessageRole::Assistant;
        $messages = [];

        foreach ($chunks as $index => $chunk) {
            $message = $this->persistOutbound(
                conversation: $conversation,
                content: $chunk,
                contentType: WhatsAppContentType::Text,
                role: $role,
                sender: $sender,
                metadata: $groupId ? ['group_id' => $groupId, 'part' => $index + 1, 'parts' => count($chunks)] : [],
            );
            $messages[] = $message;

            SendWhatsAppMessageJob::dispatch($message->id);
        }

        return $messages;
    }

    /**
     * Queue a template message. Templates bypass the 24h session-window rule.
     *
     * @param  array<int, mixed>  $componentParameters  Meta-shape components[]
     */
    public function sendTemplate(
        WhatsAppConversation $conversation,
        WhatsAppTemplate $template,
        array $componentParameters = [],
        ?User $sender = null,
    ): WhatsAppMessage {
        $this->assertCanSend($conversation, isTemplate: true);

        $message = $this->persistOutbound(
            conversation: $conversation,
            content: $this->renderTemplateSummary($template, $componentParameters),
            contentType: WhatsAppContentType::Template,
            role: MessageRole::Assistant,
            sender: $sender,
            metadata: [
                'template_name' => $template->name,
                'template_language' => $template->language,
                'components' => $componentParameters,
            ],
            templateName: $template->name,
            templateLanguage: $template->language,
        );

        SendWhatsAppMessageJob::dispatch($message->id);

        return $message;
    }

    /**
     * @throws WhatsAppSendException when blocked by compliance rules
     */
    private function assertCanSend(WhatsAppConversation $conversation, bool $isTemplate): void
    {
        $channel = $conversation->channel;

        if (! $channel || $channel->status !== ChannelStatus::Active) {
            throw new WhatsAppSendException('channel_not_active', 'The channel is not active — outbound messages are disabled.');
        }

        $contact = $conversation->contact;
        if (! $contact) {
            throw new WhatsAppSendException('contact_missing', 'Conversation has no contact — cannot send.');
        }

        if ($contact->isOptedOut()) {
            throw new WhatsAppSendException('opted_out', 'Contact has opted out — outbound delivery is suppressed.');
        }

        if (! $isTemplate && ! $contact->isWithinSessionWindow()) {
            throw new WhatsAppSendException(
                'window_expired',
                'Last inbound message is older than 24h — send a template message instead.',
            );
        }
    }

    private function persistOutbound(
        WhatsAppConversation $conversation,
        string $content,
        WhatsAppContentType $contentType,
        MessageRole $role,
        ?User $sender,
        array $metadata = [],
        ?string $templateName = null,
        ?string $templateLanguage = null,
    ): WhatsAppMessage {
        $message = WhatsAppMessage::create([
            'whatsapp_conversation_id' => $conversation->id,
            'role' => $role,
            'direction' => MessageDirection::Outbound,
            'content' => $content,
            'content_type' => $contentType,
            'template_name' => $templateName,
            'template_language' => $templateLanguage,
            'status' => MessageStatus::Pending,
            'sender_user_id' => $sender?->id,
            'metadata' => $metadata,
        ]);

        $conversation->increment('message_count');
        $conversation->update(['last_outbound_at' => now()]);

        Log::channel('whatsapp')->info('outbound.queued', [
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'content_type' => $contentType->value,
            'sender_user_id' => $sender?->id,
        ]);

        return $message;
    }

    /**
     * Split a string into <=4096-char chunks on word boundaries when possible.
     *
     * @return array<int, string>
     */
    public function chunkText(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [''];
        }

        if (strlen($text) <= self::MAX_TEXT_CHUNK) {
            return [$text];
        }

        $chunks = [];
        while (strlen($text) > self::MAX_TEXT_CHUNK) {
            $slice = substr($text, 0, self::MAX_TEXT_CHUNK);
            $breakAt = strrpos($slice, ' ');
            if ($breakAt === false || $breakAt < self::MAX_TEXT_CHUNK - 200) {
                // No whitespace near the end — hard-split.
                $chunks[] = $slice;
                $text = substr($text, self::MAX_TEXT_CHUNK);
            } else {
                $chunks[] = substr($text, 0, $breakAt);
                $text = ltrim(substr($text, $breakAt));
            }
        }

        if ($text !== '') {
            $chunks[] = $text;
        }

        return $chunks;
    }

    private function renderTemplateSummary(WhatsAppTemplate $template, array $components): string
    {
        // Readable placeholder stored alongside the actual template payload
        // in metadata. UI can render fully from components if needed.
        return "[template] {$template->name} ({$template->language})";
    }
}
