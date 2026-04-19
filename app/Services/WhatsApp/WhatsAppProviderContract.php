<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppConnection;
use App\Models\WhatsAppTemplate;
use Illuminate\Http\Request;

/**
 * Contract every WhatsApp provider (Meta Cloud API, Twilio, 360dialog, …)
 * implements. The rest of the system talks to this interface so swapping
 * providers is a bind-time decision, not a rewrite.
 *
 * Return shape for send*: `['wamid' => string|null, 'provider_message_id' => string|null]`.
 */
interface WhatsAppProviderContract
{
    /**
     * @return array{wamid: ?string, provider_message_id: ?string}
     */
    public function sendText(WhatsAppConnection $connection, string $to, string $text): array;

    /**
     * @param  array<string, mixed>  $media  shape: {type, link|id, caption?, filename?}
     * @return array{wamid: ?string, provider_message_id: ?string}
     */
    public function sendMedia(WhatsAppConnection $connection, string $to, string $type, array $media): array;

    /**
     * @param  array<int, mixed>  $componentParameters  Meta components[] payload
     * @return array{wamid: ?string, provider_message_id: ?string}
     */
    public function sendTemplate(
        WhatsAppConnection $connection,
        string $to,
        WhatsAppTemplate $template,
        array $componentParameters = [],
    ): array;

    /**
     * Download a media blob previously announced via webhook. Returns the raw
     * bytes (streaming left to the caller — media is capped at 16 MB by Meta).
     */
    public function downloadMedia(WhatsAppConnection $connection, string $mediaId): string;

    /**
     * Validate an inbound webhook's `X-Hub-Signature-256` header against the
     * connection's app_secret using a constant-time compare.
     */
    public function verifyWebhookSignature(WhatsAppConnection $connection, Request $request): bool;
}
