<?php

namespace App\Services\Workflows;

use App\Models\User;
use App\Models\WorkflowProposal;
use Illuminate\Support\Facades\Log;

/**
 * Resolves gated workflow-write proposals (FR-5.3/9.3). Approve executes the
 * proposed action for real through the shared engine write path (the same code
 * a non-gated run would use); dismiss discards it. Both are single-shot: a
 * proposal that is not `pending` can no longer be acted on.
 */
class WorkflowProposalService
{
    public function __construct(private WorkflowEngine $engine) {}

    /**
     * @return array{ok: bool, result?: array<string, mixed>, error?: string}
     */
    public function approve(WorkflowProposal $proposal, User $user): array
    {
        if (! $proposal->isPending()) {
            return ['ok' => false, 'error' => 'This proposal has already been resolved.'];
        }

        $app = $proposal->app;
        if ($app === null) {
            return ['ok' => false, 'error' => 'The proposal\'s app no longer exists.'];
        }

        try {
            $result = $this->engine->executeApprovedAction($app, $proposal->action ?? [], $user);
        } catch (\Throwable $e) {
            $proposal->forceFill([
                'status' => 'failed',
                'resolved_by_user_id' => $user->id,
                'resolved_at' => now(),
            ])->save();

            Log::warning('Workflow proposal execution failed', [
                'proposal_id' => $proposal->id,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $proposal->forceFill([
            'status' => 'approved',
            'resolved_by_user_id' => $user->id,
            'resolved_at' => now(),
        ])->save();

        return ['ok' => true, 'result' => $result];
    }

    public function dismiss(WorkflowProposal $proposal, User $user): bool
    {
        if (! $proposal->isPending()) {
            return false;
        }

        $proposal->forceFill([
            'status' => 'dismissed',
            'resolved_by_user_id' => $user->id,
            'resolved_at' => now(),
        ])->save();

        return true;
    }
}
