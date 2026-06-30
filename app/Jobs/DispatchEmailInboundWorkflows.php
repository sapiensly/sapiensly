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
 * Fans a normalised inbound email out to every `email.inbound` workflow in the
 * owner's org that binds the integration (and matches the optional to/subject
 * filters). Mirrors DispatchIntegrationEventWorkflows: the binding lives in the
 * trigger (`integration_id`), per-(workflow,delivery) dedupe via WebhookDelivery
 * makes provider retries safe.
 */
class DispatchEmailInboundWorkflows implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $payload  {integration_id, email, delivery_key}
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
        $email = is_array($this->payload['email'] ?? null) ? $this->payload['email'] : [];
        $runPayload = collect($this->payload)->except('delivery_key')->all();

        $this->ownerApps()->cursor()->each(function (App $app) use ($manifests, $engine, $deliveryKey, $email, $runPayload): void {
            $manifest = $manifests->getActiveManifest($app);
            if (! is_array($manifest)) {
                return;
            }

            foreach ($manifest['workflows'] ?? [] as $workflow) {
                if (! is_array($workflow) || ($workflow['enabled'] ?? true) === false) {
                    continue;
                }

                $trigger = $workflow['trigger'] ?? [];
                if (($trigger['type'] ?? null) !== 'email.inbound') {
                    continue;
                }
                if (($trigger['integration_id'] ?? null) !== $this->integrationId) {
                    continue;
                }

                if (! $this->matchesFilters($trigger, $email)) {
                    continue;
                }

                if ($deliveryKey !== '' && ! $this->claimDelivery($app->id, (string) $workflow['id'], $deliveryKey)) {
                    continue;
                }

                try {
                    $engine->run($app, $manifest, $workflow, 'email.inbound', $runPayload, null);
                } catch (\Throwable $e) {
                    Log::warning('Email-inbound workflow run failed', [
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
     * @param  array<string, mixed>  $trigger
     * @param  array<string, mixed>  $email
     */
    private function matchesFilters(array $trigger, array $email): bool
    {
        $toContains = trim((string) ($trigger['to_contains'] ?? ''));
        if ($toContains !== '' && mb_stripos((string) ($email['to'] ?? ''), $toContains) === false) {
            return false;
        }

        $subjectContains = trim((string) ($trigger['subject_contains'] ?? ''));
        if ($subjectContains !== '' && mb_stripos((string) ($email['subject'] ?? ''), $subjectContains) === false) {
            return false;
        }

        return true;
    }

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
