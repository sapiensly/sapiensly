<?php

namespace App\Services\Capabilities\PostCall;

use App\Models\CrmUpdateProposal;
use App\Models\User;

/**
 * Capability #0001 — the HubSpot post-call agent. A fixed pipeline (not a
 * composing agent): read the call, draft the CRM update it implies, and return a
 * pending proposal for human approval. Applying it is a separate, gated step
 * ({@see ApplyCrmUpdate}) — drafting never touches the system of record.
 *
 * See docs/capabilities/0001-hubspot-post-call-agent.md.
 */
class PostCallAgent
{
    public function __construct(
        private readonly FetchCallContext $fetch,
        private readonly DraftCrmUpdate $draft,
    ) {}

    /**
     * Read → propose. Returns the persisted, pending proposal. No write to the
     * system of record happens here.
     */
    public function run(string $callId, User $user): CrmUpdateProposal
    {
        $call = $this->fetch->fetch($callId);

        return $this->draft->draft($call, $user);
    }
}
