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
 * Runs a single `schedule`-triggered workflow for one fire. Dispatched by the
 * `flows:dispatch-scheduled` command once a cron expression is due. The owning
 * tenant scope is derived from the App (platform schema) and established before
 * the engine touches tenant rows (workflow_runs/records), mirroring the WhatsApp
 * webhook job. Idempotency-per-fire is enforced at dispatch time by the command.
 */
class RunScheduledWorkflowJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public string $appId,
        public string $workflowId,
        public ?string $organizationId,
        public ?int $userId,
        public string $firedAt,
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
            $engine->run($app, $manifest, $workflow, 'schedule', ['scheduled_at' => $this->firedAt], null);
        } catch (\Throwable $e) {
            Log::warning('Scheduled workflow run failed', [
                'app_id' => $this->appId,
                'workflow_id' => $this->workflowId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
