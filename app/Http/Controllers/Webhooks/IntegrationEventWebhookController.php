<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\DispatchIntegrationEventWorkflows;
use App\Models\Integration;
use App\Services\Workflows\IntegrationWebhookSignature;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public ingress for `integration.event` workflows. One signed URL per
 * Integration (`/webhooks/integrations/{integration}`) fans out to every app
 * workflow in the owner's org whose trigger binds that integration. Admission
 * here: the integration must have inbound configured, the provider's signature
 * must verify against the integration's stored secret; only then is the fan-out
 * enqueued (per-workflow dedupe + event filtering happen in the job).
 */
class IntegrationEventWebhookController extends Controller
{
    public function __construct(private IntegrationWebhookSignature $signatures) {}

    public function receive(Request $request, Integration $integration): JsonResponse
    {
        // Don't reveal whether the integration exists when inbound isn't set up.
        if (! $this->signatures->isEnabled($integration)) {
            throw new NotFoundHttpException('No inbound webhook configured for this integration.');
        }

        $rawBody = $request->getContent();
        $provided = $request->header($this->signatures->headerName($integration));

        if (! $this->signatures->verify($integration, $rawBody, $provided)) {
            return response()->json(['error' => 'Invalid signature.'], 401);
        }

        $body = $request->json()->all();

        // Webhooks arrive unauthenticated — scope from the integration (platform).
        app(TenantContext::class)->set($integration->organization_id, $integration->user_id);

        DispatchIntegrationEventWorkflows::dispatch(
            $integration->id,
            $integration->organization_id,
            $integration->user_id,
            [
                'integration_id' => $integration->id,
                'event' => $this->resolveEvent($request, $body),
                'body' => $body,
                'headers' => $this->safeHeaders($request),
                'delivery_key' => $this->deliveryKey($request, $rawBody),
            ],
        );

        return response()->json(['status' => 'accepted'], 202);
    }

    /**
     * The incoming event identifier, resolved from the common provider
     * conventions (GitHub/Shopify use a header; Stripe/others a body `type`).
     *
     * @param  array<string, mixed>  $body
     */
    private function resolveEvent(Request $request, array $body): string
    {
        foreach (['X-Event-Type', 'X-GitHub-Event', 'X-Shopify-Topic'] as $header) {
            $value = $request->header($header);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $type = $body['type'] ?? null;

        return is_scalar($type) ? (string) $type : '';
    }

    /**
     * Stable dedupe identity for this delivery: the provider's delivery id from
     * a known header when present, else a hash of the raw body (provider
     * retries resend identical bodies).
     */
    private function deliveryKey(Request $request, string $rawBody): string
    {
        foreach (['X-GitHub-Delivery', 'X-Shopify-Webhook-Id', 'X-Request-Id'] as $header) {
            $value = $request->header($header);
            if (is_string($value) && $value !== '') {
                return substr($value, 0, 255);
            }
        }

        return 'sha256:'.hash('sha256', $rawBody);
    }

    /**
     * @return array<string, string>
     */
    private function safeHeaders(Request $request): array
    {
        $allow = [
            'content-type',
            'user-agent',
            'x-event-type',
            'x-github-event',
            'x-github-delivery',
            'x-shopify-topic',
        ];
        $headers = [];
        foreach ($allow as $name) {
            $value = $request->header($name);
            if ($value !== null) {
                $headers[$name] = (string) $value;
            }
        }

        return $headers;
    }
}
