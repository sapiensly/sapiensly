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
 * Runs an `integration.poll` workflow for one newly-seen item. Dispatched by the
 * `flows:dispatch-polls` sweep, which has already polled the connected tool and
 * advanced the per-workflow watermark — so dedupe lives in the cursor, not here.
 * Tenant scope is re-established from the App, mirroring RunDateReachedWorkflowJob.
 */
class RunIntegrationPollWorkflowJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $payload  {tool_id, item, watermark}
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
            $engine->run($app, $manifest, $workflow, 'integration.poll', $this->payload, null);
        } catch (\Throwable $e) {
            Log::warning('Integration-poll workflow run failed', [
                'app_id' => $this->appId,
                'workflow_id' => $this->workflowId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
