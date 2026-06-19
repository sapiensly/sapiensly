<?php

namespace App\Jobs;

use App\Jobs\Middleware\EstablishTenantContext;
use App\Models\App;
use App\Services\Manifest\AppManifestService;
use App\Services\Workflows\WorkflowEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Runs a single `webhook.inbound`-triggered workflow for one accepted delivery.
 * Admission (signature, rate limit, dedupe) already happened in the controller,
 * which 202'd before enqueuing — so this never blocks the provider. Tenant scope
 * is re-established from the App before the engine touches tenant rows.
 */
class RunWebhookWorkflowJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $appId,
        public string $workflowId,
        public ?string $organizationId,
        public ?int $userId,
        public array $payload,
    ) {
        $this->onQueue('workflows');
    }

    public function middleware(): array
    {
        return [EstablishTenantContext::fromOwner($this->organizationId, $this->userId)];
    }

    public function handle(AppManifestService $manifests, WorkflowEngine $engine): void
    {
        $app = App::query()->find($this->appId);
        if ($app === null) {
            return;
        }

        $manifest = $manifests->getActiveManifest($app);
        if (! is_array($manifest)) {
            return;
        }

        $workflow = collect($manifest['workflows'] ?? [])
            ->first(fn (array $w): bool => ($w['id'] ?? null) === $this->workflowId);

        if (! is_array($workflow) || ($workflow['enabled'] ?? true) === false) {
            return;
        }

        try {
            $engine->run($app, $manifest, $workflow, 'webhook.inbound', ['webhook' => $this->payload], null);
        } catch (\Throwable $e) {
            Log::warning('Webhook workflow run failed', [
                'app_id' => $this->appId,
                'workflow_id' => $this->workflowId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
