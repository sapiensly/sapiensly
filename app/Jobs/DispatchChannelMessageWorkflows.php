<?php

namespace App\Jobs;

use App\Jobs\Middleware\EstablishTenantContext;
use App\Models\App;
use App\Services\Manifest\AppManifestService;
use App\Services\Workflows\WorkflowEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Runs every `channel.message_received` workflow bound to a channel when an
 * inbound message arrives on it (WhatsApp or widget). Channels and Apps are
 * disjoint, so — like webhook.inbound — the binding lives in the workflow
 * trigger (`channel_id`): this job enumerates the owner's apps and lets each
 * manifest opt in by matching channel_id. Dispatched once per inbound message
 * from the WhatsApp/widget inbound paths, so it has no idempotency of its own.
 */
class DispatchChannelMessageWorkflows implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $payload  {channel, message, contact, conversation_id}
     */
    public function __construct(
        public string $channelId,
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
        $this->ownerApps()->cursor()->each(function (App $app) use ($manifests, $engine): void {
            $manifest = $manifests->getActiveManifest($app);
            if (! is_array($manifest)) {
                return;
            }

            foreach ($manifest['workflows'] ?? [] as $workflow) {
                if (! is_array($workflow) || ($workflow['enabled'] ?? true) === false) {
                    continue;
                }

                $trigger = $workflow['trigger'] ?? [];
                if (($trigger['type'] ?? null) !== 'channel.message_received') {
                    continue;
                }
                if (($trigger['channel_id'] ?? null) !== $this->channelId) {
                    continue;
                }

                // Optional case-insensitive keyword gate on the message text.
                $contains = trim((string) ($trigger['contains'] ?? ''));
                if ($contains !== '') {
                    $text = (string) ($this->payload['message']['text'] ?? '');
                    if (mb_stripos($text, $contains) === false) {
                        continue;
                    }
                }

                try {
                    $engine->run($app, $manifest, $workflow, 'channel.message_received', $this->payload, null);
                } catch (\Throwable $e) {
                    Log::warning('Channel-message workflow run failed', [
                        'app_id' => $app->id,
                        'workflow_id' => $workflow['id'] ?? null,
                        'channel_id' => $this->channelId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    /**
     * Apps in the message's owner scope (business → organization, personal →
     * user) that have an active version to read a manifest from.
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
