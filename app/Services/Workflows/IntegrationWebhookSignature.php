<?php

namespace App\Services\Workflows;

use App\Models\Integration;

/**
 * Verifies a third-party provider's webhook signature for an `integration.event`
 * trigger. Unlike WorkflowWebhookSignature (which derives Sapiensly's own
 * secret), here the secret belongs to the provider and is stored on the
 * Integration's encrypted `auth_config`:
 *   - webhook_secret            the provider's signing secret (required)
 *   - webhook_signature_header  header carrying the signature (default X-Hub-Signature-256)
 *   - webhook_signature_scheme  only `hmac_sha256` for now
 *
 * v1 implements HMAC-SHA256 over the raw body (the GitHub/Shopify/Meta scheme,
 * matching MetaCloudProvider::verifyWebhookSignature). Fails closed: no secret
 * or unknown scheme ⇒ reject.
 */
class IntegrationWebhookSignature
{
    public function isEnabled(Integration $integration): bool
    {
        $secret = $integration->auth_config['webhook_secret'] ?? null;

        return is_string($secret) && $secret !== '';
    }

    public function headerName(Integration $integration): string
    {
        $header = $integration->auth_config['webhook_signature_header'] ?? null;

        return is_string($header) && $header !== '' ? $header : 'X-Hub-Signature-256';
    }

    public function verify(Integration $integration, string $rawBody, ?string $provided): bool
    {
        $secret = (string) ($integration->auth_config['webhook_secret'] ?? '');
        if ($secret === '' || $provided === null || $provided === '') {
            return false;
        }

        $scheme = (string) ($integration->auth_config['webhook_signature_scheme'] ?? 'hmac_sha256');
        if ($scheme !== 'hmac_sha256') {
            return false;
        }

        // Providers send either a bare hex digest or `sha256=<hex>`.
        $candidate = str_starts_with($provided, 'sha256=') ? substr($provided, 7) : $provided;
        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $candidate);
    }
}
