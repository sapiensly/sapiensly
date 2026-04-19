<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppConnection;
use App\Models\WhatsAppTemplate;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Meta WhatsApp Cloud API adapter. Uses Laravel's Http facade with a short
 * timeout; retries live at the Job layer, not here. Credentials are pulled
 * from the connection's encrypted `auth_config` cast — never logged.
 */
class MetaCloudProvider implements WhatsAppProviderContract
{
    private const DEFAULT_GRAPH_VERSION = 'v20.0';

    private const MESSAGES_TIMEOUT_SECONDS = 10;

    public function sendText(WhatsAppConnection $connection, string $to, string $text): array
    {
        $response = $this->client($connection)->post(
            $this->messagesEndpoint($connection),
            [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $to,
                'type' => 'text',
                'text' => ['preview_url' => false, 'body' => $text],
            ],
        );

        return $this->parseResponse($response, "sendText to={$to}");
    }

    public function sendMedia(WhatsAppConnection $connection, string $to, string $type, array $media): array
    {
        $response = $this->client($connection)->post(
            $this->messagesEndpoint($connection),
            [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $to,
                'type' => $type,
                $type => $media,
            ],
        );

        return $this->parseResponse($response, "sendMedia to={$to} type={$type}");
    }

    public function sendTemplate(
        WhatsAppConnection $connection,
        string $to,
        WhatsAppTemplate $template,
        array $componentParameters = [],
    ): array {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $template->name,
                'language' => ['code' => $template->language],
            ],
        ];

        if (! empty($componentParameters)) {
            $payload['template']['components'] = $componentParameters;
        }

        $response = $this->client($connection)->post(
            $this->messagesEndpoint($connection),
            $payload,
        );

        return $this->parseResponse($response, "sendTemplate to={$to} tpl={$template->name}");
    }

    public function downloadMedia(WhatsAppConnection $connection, string $mediaId): string
    {
        // Step 1: resolve the signed, short-lived media URL.
        $meta = $this->client($connection)->get(
            sprintf('%s/%s', $this->baseUrl($connection), $mediaId),
        );

        if (! $meta->successful()) {
            throw new \RuntimeException('Could not resolve media URL: '.$meta->status());
        }

        $url = (string) ($meta->json('url') ?? '');
        if ($url === '') {
            throw new \RuntimeException('Media URL missing from Graph response.');
        }

        // Step 2: fetch the binary payload with the same access token.
        $binary = $this->client($connection)
            ->withOptions(['timeout' => 30])
            ->get($url);

        if (! $binary->successful()) {
            throw new \RuntimeException('Media download failed: '.$binary->status());
        }

        return $binary->body();
    }

    public function verifyWebhookSignature(WhatsAppConnection $connection, Request $request): bool
    {
        $header = (string) $request->header('X-Hub-Signature-256', '');
        if (! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $appSecret = $connection->auth_config['app_secret'] ?? '';
        if ($appSecret === '') {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $appSecret);

        return hash_equals($expected, $header);
    }

    private function client(WhatsAppConnection $connection): PendingRequest
    {
        $cfg = $connection->auth_config ?? [];

        return Http::withToken($cfg['access_token'] ?? '')
            ->timeout(self::MESSAGES_TIMEOUT_SECONDS)
            ->withOptions(['verify' => ! $connection->allow_insecure_tls])
            ->acceptJson();
    }

    private function baseUrl(WhatsAppConnection $connection): string
    {
        $cfg = $connection->auth_config ?? [];
        $version = $cfg['graph_api_version'] ?? self::DEFAULT_GRAPH_VERSION;

        return "https://graph.facebook.com/{$version}";
    }

    private function messagesEndpoint(WhatsAppConnection $connection): string
    {
        $cfg = $connection->auth_config ?? [];
        $phoneNumberId = $cfg['phone_number_id'] ?? $connection->phone_number_id;

        return sprintf('%s/%s/messages', $this->baseUrl($connection), $phoneNumberId);
    }

    /**
     * @return array{wamid: ?string, provider_message_id: ?string}
     */
    private function parseResponse(Response $response, string $context): array
    {
        if (! $response->successful()) {
            $body = $response->json();
            $errCode = $body['error']['code'] ?? null;
            $errMsg = $body['error']['message'] ?? $response->body();

            Log::channel('whatsapp')->warning('meta_cloud.send_failed', [
                'context' => $context,
                'status' => $response->status(),
                'error_code' => $errCode,
                'error_message' => $errMsg,
            ]);

            throw new WhatsAppProviderException(
                (string) $errMsg,
                (int) ($errCode ?? 0),
                $response->status(),
            );
        }

        $json = $response->json();

        return [
            'wamid' => $json['messages'][0]['id'] ?? null,
            'provider_message_id' => $json['messages'][0]['id'] ?? null,
        ];
    }
}
