<?php

namespace App\Jobs;

use App\Jobs\Middleware\EstablishTenantContext;
use App\Models\App;
use App\Models\WebhookDelivery;
use App\Services\Manifest\AppManifestService;
use App\Services\Workflows\WorkflowEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fans an inbound integration webhook out to every `integration.event` workflow
 * in the owner's org that binds the integration (and matches the optional event
 * filter). Integrations and Apps are disjoint, so — like channel.message_received
 * — the binding lives in the trigger (`integration_id`). Dispatched once per
 * verified delivery by IntegrationEventWebhookController; per-workflow dedupe via
 * WebhookDelivery makes provider retries safe.
 */
class DispatchIntegrationEventWorkflows implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $payload  {integration_id, event, body, headers, delivery_key}
     */
    public function __construct(
        public string $integrationId,
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
        $deliveryKey = (string) ($this->payload['delivery_key'] ?? '');
        $wantEvent = trim((string) ($this->payload['event'] ?? ''));
        // The workflow never sees the internal dedupe key.
        $runPayload = collect($this->payload)->except('delivery_key')->all();

        $this->ownerApps()->cursor()->each(function (App $app) use ($manifests, $engine, $deliveryKey, $wantEvent, $runPayload): void {
            $manifest = $manifests->getActiveManifest($app);
            if (! is_array($manifest)) {
                return;
            }

            foreach ($manifest['workflows'] ?? [] as $workflow) {
                if (! is_array($workflow) || ($workflow['enabled'] ?? true) === false) {
                    continue;
                }

                $trigger = $workflow['trigger'] ?? [];
                if (($trigger['type'] ?? null) !== 'integration.event') {
                    continue;
                }
                if (($trigger['integration_id'] ?? null) !== $this->integrationId) {
                    continue;
                }

                // Optional per-workflow event filter (case-insensitive).
                $filter = trim((string) ($trigger['event'] ?? ''));
                if ($filter !== '' && strcasecmp($filter, $wantEvent) !== 0) {
                    continue;
                }

                // Per-workflow dedupe: a provider retry of the same delivery
                // must not re-run a workflow that already handled it.
                if ($deliveryKey !== '' && ! $this->claimDelivery($app->id, (string) $workflow['id'], $deliveryKey)) {
                    continue;
                }

                try {
                    $engine->run($app, $manifest, $workflow, 'integration.event', $runPayload, null);
                } catch (\Throwable $e) {
                    Log::warning('Integration-event workflow run failed', [
                        'app_id' => $app->id,
                        'workflow_id' => $workflow['id'] ?? null,
                        'integration_id' => $this->integrationId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    /**
     * Reserve this (workflow, delivery) pair; false if already taken (duplicate).
     */
    private function claimDelivery(string $appId, string $workflowId, string $deliveryKey): bool
    {
        try {
            DB::transaction(fn () => WebhookDelivery::create([
                'app_id' => $appId,
                'workflow_id' => $workflowId,
                'delivery_key' => $deliveryKey,
                'status' => 'accepted',
            ]));

            return true;
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23505') {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Apps in the integration's owner scope with an active version.
     *
     * @return Builder<App>
     */
    private function ownerApps(): Builder
    {
        $query = App::query()->whereNotNull('current_version_id');

        if ($this->organizationId !== null) {
            return $query->where('organization_id', $this->organizationId);
        }

        return $query->whereNull('organization_id')->where('user_id', $this->userId);
    }
}
