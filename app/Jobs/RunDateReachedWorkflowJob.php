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
 * Runs a single `record.date_reached`-triggered workflow for one record whose
 * date field reached the configured offset. Dispatched by the
 * `flows:dispatch-date-reached` sweep. Tenant scope is derived from the App and
 * established before the engine touches tenant rows, mirroring
 * RunScheduledWorkflowJob. Exactly-once is enforced at sweep time by the
 * monotonic per-workflow cursor, so this job has no idempotency of its own.
 */
class RunDateReachedWorkflowJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $record  the record payload ({id, data, ...})
     */
    public function __construct(
        public string $appId,
        public string $workflowId,
        public ?string $organizationId,
        public ?int $userId,
        public array $record,
        public string $reachedAt,
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
            $engine->run($app, $manifest, $workflow, 'record.date_reached', [
                'record' => $this->record,
                'reached_at' => $this->reachedAt,
            ], null);
        } catch (\Throwable $e) {
            Log::warning('Date-reached workflow run failed', [
                'app_id' => $this->appId,
                'workflow_id' => $this->workflowId,
                'record_id' => $this->record['id'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
