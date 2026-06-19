<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\RunWebhookWorkflowJob;
use App\Models\App;
use App\Models\WebhookDelivery;
use App\Services\Manifest\AppManifestService;
use App\Services\Workflows\WorkflowWebhookSignature;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public ingress for `webhook.inbound`-triggered workflows. Each workflow owns a
 * dedicated signed URL (`/webhooks/flows/{app}/{workflow}`) — it never inherits
 * the widget/whatsapp path. Admission happens here, before any tenant work or
 * enqueue (FR-4.1): valid HMAC signature, then per-workflow rate limiting (route
 * middleware), then delivery dedupe (FR-4.3). Only then is the run enqueued and
 * the provider gets a fast 202.
 */
class FlowWebhookController extends Controller
{
    public function __construct(
        private AppManifestService $manifests,
        private WorkflowWebhookSignature $signatures,
    ) {}

    public function receive(Request $request, App $app, string $workflow): JsonResponse
    {
        $manifest = $this->manifests->getActiveManifest($app);
        $definition = collect($manifest['workflows'] ?? [])
            ->first(fn (array $w): bool => ($w['id'] ?? null) === $workflow
                && ($w['trigger']['type'] ?? null) === 'webhook.inbound');

        if (! is_array($definition)) {
            throw new NotFoundHttpException('No inbound webhook workflow at this URL.');
        }

        if (($definition['enabled'] ?? true) === false) {
            return response()->json(['error' => 'Workflow is disabled.'], 422);
        }

        $trigger = $definition['trigger'];
        $header = (string) ($trigger['signature_header'] ?? 'X-Sapiensly-Signature');
        $rawBody = $request->getContent();

        if (! $this->signatures->verify($app->id, $workflow, $rawBody, $request->header($header))) {
            return response()->json(['error' => 'Invalid signature.'], 401);
        }

        $payload = [
            'body' => $request->json()->all(),
            'headers' => $this->safeHeaders($request),
        ];

        // Webhooks arrive unauthenticated — derive the tenant scope from the App
        // (platform schema) before recording the delivery or enqueuing the run.
        app(TenantContext::class)->set($app->organization_id, $app->user_id);

        $deliveryKey = $this->deliveryKey($trigger, $payload['body'], $rawBody);

        try {
            // Wrap in a savepoint so a dedupe collision rolls back only this
            // insert — not an enclosing transaction (e.g. a test's).
            DB::transaction(fn () => WebhookDelivery::create([
                'app_id' => $app->id,
                'workflow_id' => $workflow,
                'delivery_key' => $deliveryKey,
                'status' => 'accepted',
            ]));
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return response()->json(['status' => 'duplicate'], 200);
            }
            throw $e;
        }

        RunWebhookWorkflowJob::dispatch($app->id, $workflow, $app->organization_id, $app->user_id, $payload);

        return response()->json(['status' => 'accepted'], 202);
    }

    /**
     * The dedupe identity for this delivery: the provider's delivery id at the
     * configured JSON path when present, else a hash of the raw body (FR-4.3).
     *
     * @param  array<string, mixed>  $trigger
     * @param  array<string, mixed>  $body
     */
    private function deliveryKey(array $trigger, array $body, string $rawBody): string
    {
        $path = $trigger['dedupe_path'] ?? null;
        if (is_string($path) && $path !== '') {
            $value = data_get($body, $path);
            if (is_scalar($value) && (string) $value !== '') {
                return substr((string) $value, 0, 255);
            }
        }

        return 'sha256:'.hash('sha256', $rawBody);
    }

    /**
     * @return array<string, string>
     */
    private function safeHeaders(Request $request): array
    {
        $allow = ['content-type', 'user-agent', 'x-github-event', 'x-event-type'];
        $headers = [];
        foreach ($allow as $name) {
            $value = $request->header($name);
            if ($value !== null) {
                $headers[$name] = (string) $value;
            }
        }

        return $headers;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '23505';
    }
}
