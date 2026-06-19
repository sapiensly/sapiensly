<?php

namespace App\Services\Workflows;

use Illuminate\Support\Facades\Config;

/**
 * Derives and verifies the per-workflow HMAC secret for `webhook.inbound`
 * triggers. The secret is derived deterministically from the app key plus the
 * app/workflow ids — it is stable (displayable to the user, copy-pasteable into
 * the provider), per-workflow, and needs no storage. A provider signs the raw
 * body with HMAC-SHA256 and sends `sha256=<hex>`; we recompute and constant-time
 * compare.
 */
class WorkflowWebhookSignature
{
    public function secretFor(string $appId, string $workflowId): string
    {
        return hash_hmac(
            'sha256',
            "flows:webhook:{$appId}:{$workflowId}",
            (string) Config::get('app.key'),
        );
    }

    /**
     * Verify a provider-supplied signature header against the raw request body.
     * Accepts both bare hex and the conventional `sha256=<hex>` prefix.
     */
    public function verify(string $appId, string $workflowId, string $rawBody, ?string $provided): bool
    {
        if ($provided === null || $provided === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $this->secretFor($appId, $workflowId));
        $candidate = str_starts_with($provided, 'sha256=') ? substr($provided, 7) : $provided;

        return hash_equals($expected, $candidate);
    }
}
