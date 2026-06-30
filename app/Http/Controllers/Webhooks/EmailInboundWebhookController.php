<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\DispatchEmailInboundWorkflows;
use App\Models\Integration;
use App\Services\Workflows\InboundEmailNormalizer;
use App\Services\Workflows\IntegrationWebhookSignature;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public ingress for `email.inbound` workflows — one URL per Integration
 * (`/webhooks/email/{integration}`) that any inbound-email provider can POST a
 * parsed email to. Admission accepts EITHER an HMAC-SHA256 signature (Mailgun /
 * generic) OR a shared `token` (query or X-Webhook-Token header) compared
 * constant-time — so providers that don't sign (Postmark/SendGrid) work too.
 * The payload is normalised to a canonical email and fanned out to the org's
 * email.inbound workflows binding this integration.
 */
class EmailInboundWebhookController extends Controller
{
    public function __construct(
        private IntegrationWebhookSignature $signatures,
        private InboundEmailNormalizer $normalizer,
    ) {}

    public function receive(Request $request, Integration $integration): JsonResponse
    {
        if (! $this->signatures->isEnabled($integration)) {
            throw new NotFoundHttpException('No inbound email configured for this integration.');
        }

        $rawBody = $request->getContent();
        if (! $this->admitted($request, $integration, $rawBody)) {
            return response()->json(['error' => 'Invalid signature or token.'], 401);
        }

        // Providers post JSON or multipart form — `all()` covers both.
        $body = $request->all();
        $provider = (string) ($integration->auth_config['email_provider'] ?? 'generic');
        $email = $this->normalizer->normalize($provider, $body);

        app(TenantContext::class)->set($integration->organization_id, $integration->user_id);

        DispatchEmailInboundWorkflows::dispatch(
            $integration->id,
            $integration->organization_id,
            $integration->user_id,
            [
                'integration_id' => $integration->id,
                'email' => $email,
                'delivery_key' => $this->deliveryKey($email, $rawBody),
            ],
        );

        return response()->json(['status' => 'accepted'], 202);
    }

    private function admitted(Request $request, Integration $integration, string $rawBody): bool
    {
        $provided = $request->header($this->signatures->headerName($integration));
        if ($this->signatures->verify($integration, $rawBody, $provided)) {
            return true;
        }

        // Token fallback for providers that don't sign their webhooks.
        $secret = (string) ($integration->auth_config['webhook_secret'] ?? '');
        $token = (string) ($request->query('token') ?? $request->header('X-Webhook-Token', ''));

        return $secret !== '' && $token !== '' && hash_equals($secret, $token);
    }

    /**
     * @param  array<string, string|null>  $email
     */
    private function deliveryKey(array $email, string $rawBody): string
    {
        $messageId = $email['message_id'] ?? null;
        if (is_string($messageId) && $messageId !== '') {
            return substr($messageId, 0, 255);
        }

        return 'sha256:'.hash('sha256', $rawBody);
    }
}
