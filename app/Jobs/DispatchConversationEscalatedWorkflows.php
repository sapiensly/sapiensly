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
 * Runs every `conversation.escalated` workflow bound to a channel when a bot
 * hands a conversation to a person.
 *
 * This is what "notify the team" means, and it is deliberately not a new
 * notification stack: the workflow engine already sends email, posts to Slack
 * and writes records, and an organization that has wired any of that up gets to
 * reuse it. The bot flow node has carried a `notify` flag since it was written;
 * until now nothing was on the other end of it.
 *
 * Channels and Apps are disjoint, so the binding lives in the workflow trigger
 * (`channel_id`) and this job enumerates the owner's apps, exactly like
 * DispatchChannelMessageWorkflows.
 */
class DispatchConversationEscalatedWorkflows implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $payload  {channel, chatbot, conversation_id, reason, mode, contact}
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
                if (($trigger['type'] ?? null) !== 'conversation.escalated') {
                    continue;
                }
                if (($trigger['channel_id'] ?? null) !== $this->channelId) {
                    continue;
                }

                try {
                    $engine->run($app, $manifest, $workflow, 'conversation.escalated', $this->payload, null);
                } catch (\Throwable $e) {
                    Log::warning('Conversation-escalated workflow run failed', [
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
     * Apps in the escalation's owner scope (business → organization, personal →
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
