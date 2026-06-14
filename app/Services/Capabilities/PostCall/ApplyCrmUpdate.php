<?php

namespace App\Services\Capabilities\PostCall;

use App\Models\CrmUpdateProposal;
use App\Models\User;
use App\Services\Capabilities\PostCall\Contracts\CrmConnector;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Write sub-capability of #0001 — **the gate**. Executes an approved proposal
 * against the CRM. Never invoked by drafting; requires an explicit approver
 * (autonomy is approval-gated in v1, never `safe`). Idempotent on the proposal:
 * re-applying an already-applied proposal is a no-op that returns the prior result.
 */
class ApplyCrmUpdate
{
    public function __construct(private readonly CrmConnector $connector) {}

    public function apply(CrmUpdateProposal $proposal, User $approver): CrmUpdateProposal
    {
        if ($proposal->status === 'applied') {
            return $proposal; // idempotent: already executed
        }

        if ($proposal->status !== 'pending') {
            throw new RuntimeException("Proposal {$proposal->id} is not pending (status: {$proposal->status}).");
        }

        $result = $this->connector->applyUpdate($proposal);

        $proposal->forceFill([
            'status' => $result->status,
            'external_object_id' => $result->externalObjectId,
            'error' => $result->error,
            'approver_id' => $approver->id,
            'applied_at' => $result->status === 'applied' ? now() : null,
        ])->save();

        Log::info('Capability 0001: proposal applied', [
            'proposal_id' => $proposal->id,
            'status' => $proposal->status,
            'approver_id' => $approver->id,
        ]);

        return $proposal;
    }

    public function reject(CrmUpdateProposal $proposal): CrmUpdateProposal
    {
        if ($proposal->status === 'pending') {
            $proposal->forceFill(['status' => 'rejected'])->save();
        }

        return $proposal;
    }
}
