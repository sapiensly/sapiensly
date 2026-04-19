<?php

namespace App\Services\WhatsApp;

use App\Enums\WhatsAppContentType;

/**
 * Pure, side-effect-free decoder for the Meta Cloud API webhook payload.
 * Produces flat lists of "inbound message" and "status update" events the
 * processing job can iterate over without knowing the nested shape.
 */
class WhatsAppWebhookParser
{
    /**
     * @return array{messages: array<int, array<string, mixed>>, statuses: array<int, array<string, mixed>>}
     */
    public function parse(array $payload): array
    {
        $messages = [];
        $statuses = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? '') !== 'messages') {
                    continue;
                }

                $value = $change['value'] ?? [];

                foreach ($value['messages'] ?? [] as $message) {
                    $messages[] = $this->normalizeMessage($message, $value);
                }

                foreach ($value['statuses'] ?? [] as $status) {
                    $statuses[] = $this->normalizeStatus($status);
                }
            }
        }

        return ['messages' => $messages, 'statuses' => $statuses];
    }

    /**
     * Extract the bits we care about, normalise the shape. Group chats,
     * reactions, and edits produce payloads we explicitly skip.
     *
     * @return array<string, mixed>
     */
    private function normalizeMessage(array $message, array $value): array
    {
        $wamid = (string) ($message['id'] ?? '');
        $from = (string) ($message['from'] ?? '');
        $type = (string) ($message['type'] ?? 'text');
        $contactProfile = $this->findProfileForWaId($value, $from);

        $contentType = match ($type) {
            'text' => WhatsAppContentType::Text,
            'image' => WhatsAppContentType::Image,
            'document' => WhatsAppContentType::Document,
            'audio' => WhatsAppContentType::Audio,
            'video' => WhatsAppContentType::Video,
            'location' => WhatsAppContentType::Location,
            'sticker' => WhatsAppContentType::Sticker,
            'contacts' => WhatsAppContentType::Contacts,
            default => null,
        };

        // Unsupported (reaction/button/interactive/system/edit): flag for skip.
        $skip = $contentType === null || isset($message['reaction']) || isset($message['referral']);

        [$content, $mediaId, $mediaMime] = $this->extractContent($message, $type);

        return [
            'wamid' => $wamid,
            'wa_id' => $from,
            'profile_name' => $contactProfile,
            'content_type' => $contentType,
            'content' => $content,
            'media_id' => $mediaId,
            'media_mime' => $mediaMime,
            'wa_timestamp' => (int) ($message['timestamp'] ?? time()),
            'raw' => $message,
            'skip' => $skip,
        ];
    }

    /**
     * @return array{0: string, 1: ?string, 2: ?string} [content, media_id, media_mime]
     */
    private function extractContent(array $message, string $type): array
    {
        if ($type === 'text') {
            return [(string) ($message['text']['body'] ?? ''), null, null];
        }
        if (in_array($type, ['image', 'document', 'audio', 'video', 'sticker'], true)) {
            $media = $message[$type] ?? [];
            $caption = (string) ($media['caption'] ?? '');

            return [$caption, $media['id'] ?? null, $media['mime_type'] ?? null];
        }
        if ($type === 'location') {
            $loc = $message['location'] ?? [];
            $lat = $loc['latitude'] ?? null;
            $lng = $loc['longitude'] ?? null;
            $name = $loc['name'] ?? null;

            return [trim((string) ($name ?: "{$lat},{$lng}")), null, null];
        }

        return ['', null, null];
    }

    private function findProfileForWaId(array $value, string $waId): ?string
    {
        foreach ($value['contacts'] ?? [] as $contact) {
            if (($contact['wa_id'] ?? '') === $waId) {
                return $contact['profile']['name'] ?? null;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeStatus(array $status): array
    {
        return [
            'wamid' => (string) ($status['id'] ?? ''),
            'status' => (string) ($status['status'] ?? ''),
            'recipient_id' => (string) ($status['recipient_id'] ?? ''),
            'wa_timestamp' => (int) ($status['timestamp'] ?? time()),
            'errors' => $status['errors'] ?? null,
        ];
    }
}
