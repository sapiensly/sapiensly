<?php

namespace App\Jobs;

use App\Enums\MessageDirection;
use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Enums\WhatsAppContentType;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppConversationResolver;
use App\Services\WhatsApp\WhatsAppWebhookParser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Entry point for asynchronous inbound-message processing. Consumed from the
 * `whatsapp-webhooks` queue. The controller has already acknowledged Meta with
 * 200; any exception here goes to `failed_jobs` (Meta will NOT retry because
 * we already 200'd). Idempotency is guaranteed by the unique `wamid` constraint.
 */
class ProcessWhatsAppWebhookJob implements ShouldQueue
{
    use Queueable;

    /**
     * Keywords (locale-agnostic, upper-case compare) that immediately opt the
     * contact out of further outbound messages.
     */
    private const OPT_OUT_KEYWORDS = ['STOP', 'UNSUBSCRIBE', 'BAJA', 'PARAR', 'CANCELAR'];

    public int $tries = 3;

    public array $backoff = [5, 30, 120];

    public function __construct(
        public string $connectionId,
        public array $payload,
    ) {
        $this->onQueue('whatsapp-webhooks');
    }

    public function handle(
        WhatsAppWebhookParser $parser,
        WhatsAppConversationResolver $resolver,
    ): void {
        $connection = WhatsAppConnection::query()->find($this->connectionId);

        if ($connection === null) {
            Log::channel('whatsapp')->warning('webhook.connection_missing', [
                'connection_id' => $this->connectionId,
            ]);

            return;
        }

        $channel = $connection->channel;

        if ($channel === null) {
            Log::channel('whatsapp')->error('webhook.channel_missing', [
                'connection_id' => $connection->id,
            ]);

            return;
        }

        $parsed = $parser->parse($this->payload);

        foreach ($parsed['messages'] as $message) {
            $this->ingestMessage($channel, $resolver, $message);
        }

        foreach ($parsed['statuses'] as $status) {
            $this->applyStatusUpdate($status);
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function ingestMessage(
        Channel $channel,
        WhatsAppConversationResolver $resolver,
        array $message,
    ): void {
        if ($message['skip'] ?? false) {
            Log::channel('whatsapp')->debug('webhook.message_skipped', [
                'wamid' => $message['wamid'] ?? null,
                'channel_id' => $channel->id,
            ]);

            return;
        }

        $waId = (string) ($message['wa_id'] ?? '');
        if ($waId === '') {
            return;
        }

        $contact = $this->findOrCreateContact($channel, $waId, $message);

        $conversation = $resolver->resolveOpen($channel, $contact);

        $stored = $this->storeInboundMessage($conversation, $message);

        if ($stored === null) {
            // Duplicate wamid — silently ignore.
            return;
        }

        $conversation->forceFill([
            'last_inbound_at' => now(),
            'message_count' => $conversation->message_count + 1,
        ])->save();

        if ($stored->content_type !== null && $stored->content_type->isMedia() && ! empty($message['media_id'])) {
            DownloadWhatsAppMediaJob::dispatch($stored->id, (string) $message['media_id']);
        }

        if ($this->isOptOut($stored->content ?? '')) {
            $contact->forceFill(['opted_out_at' => now()])->save();
            Log::channel('whatsapp')->info('contact.opted_out', [
                'contact_id' => $contact->id,
                'wa_id' => $waId,
            ]);

            return;
        }

        if ($contact->isOptedOut()) {
            return;
        }

        if ($conversation->status->suppressesAutoReply()) {
            return;
        }

        GenerateWhatsAppReplyJob::dispatch($conversation->id);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function findOrCreateContact(Channel $channel, string $waId, array $message): Contact
    {
        $contact = Contact::query()
            ->where('channel_id', $channel->id)
            ->where('identifier', $waId)
            ->first();

        $phoneE164 = '+'.ltrim($waId, '+');

        if ($contact === null) {
            return Contact::create([
                'channel_id' => $channel->id,
                'identifier' => $waId,
                'profile_name' => $message['profile_name'] ?? null,
                'phone_e164' => $phoneE164,
                'last_inbound_at' => now(),
            ]);
        }

        $updates = ['last_inbound_at' => now()];

        if (! empty($message['profile_name']) && $contact->profile_name !== $message['profile_name']) {
            $updates['profile_name'] = $message['profile_name'];
        }

        if ($contact->phone_e164 === null) {
            $updates['phone_e164'] = $phoneE164;
        }

        $contact->forceFill($updates)->save();

        return $contact;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function storeInboundMessage(WhatsAppConversation $conversation, array $message): ?WhatsAppMessage
    {
        $wamid = $message['wamid'] ?? null;

        if ($wamid && WhatsAppMessage::query()->where('wamid', $wamid)->exists()) {
            return null;
        }

        $content = is_string($message['content'] ?? null) ? $message['content'] : '';

        return WhatsAppMessage::create([
            'whatsapp_conversation_id' => $conversation->id,
            'role' => MessageRole::User,
            'direction' => MessageDirection::Inbound,
            'content' => mb_scrub($content),
            'content_type' => $message['content_type'] instanceof WhatsAppContentType
                ? $message['content_type']
                : WhatsAppContentType::Text,
            'media_mime' => $message['media_mime'] ?? null,
            'wamid' => $wamid,
            'status' => MessageStatus::Delivered,
            'metadata' => [
                'wa_timestamp' => $message['wa_timestamp'] ?? null,
                'media_id' => $message['media_id'] ?? null,
            ],
        ]);
    }

    private function isOptOut(string $content): bool
    {
        $trimmed = strtoupper(trim($content));

        return in_array($trimmed, self::OPT_OUT_KEYWORDS, true);
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function applyStatusUpdate(array $status): void
    {
        $wamid = $status['wamid'] ?? '';
        if ($wamid === '') {
            return;
        }

        $message = WhatsAppMessage::query()->where('wamid', $wamid)->first();
        if ($message === null) {
            // Status for an outbound message we haven't recorded yet — log and
            // skip; Meta sometimes delivers statuses out-of-order relative to
            // our own send response.
            Log::channel('whatsapp')->debug('webhook.status_for_unknown_wamid', [
                'wamid' => $wamid,
            ]);

            return;
        }

        $next = $this->mapStatus((string) $status['status']);
        if ($next === null) {
            return;
        }

        $context = [];
        if (! empty($status['errors'])) {
            $firstError = $status['errors'][0] ?? null;
            if (is_array($firstError)) {
                $context['error_code'] = $firstError['code'] ?? null;
                $context['error_message'] = $firstError['message'] ?? $firstError['title'] ?? null;
            }
        }

        $message->advanceStatusTo($next, $context);

        if ($next === MessageStatus::Failed && ! empty($context)) {
            $message->forceFill([
                'error_code' => $context['error_code'] ?? null,
                'error_message' => $context['error_message'] ?? null,
            ])->save();
        }
    }

    private function mapStatus(string $status): ?MessageStatus
    {
        return match ($status) {
            'sent' => MessageStatus::Sent,
            'delivered' => MessageStatus::Delivered,
            'read' => MessageStatus::Read,
            'failed' => MessageStatus::Failed,
            default => null,
        };
    }
}
