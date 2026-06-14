<?php

namespace App\Services\Capabilities\PostCall\Contracts;

use App\Models\CrmUpdateProposal;
use App\Services\Capabilities\PostCall\Connectors\FakeHubSpotConnector;
use App\Services\Capabilities\PostCall\Data\CallContext;
use App\Services\Capabilities\PostCall\Data\CrmUpdateResult;
use App\Services\Capabilities\PostCall\FetchCallContext;

/**
 * The boundary to a CRM system of record for capability #0001. Today only the
 * in-memory {@see FakeHubSpotConnector}
 * implements it (the verification posture is read-first / dry-run); the real
 * HubSpot adapter slots in here later without touching the capability services.
 *
 * Reads are `remote / async / may-fail` (the contract mark); implementations may
 * throw, and {@see FetchCallContext} treats a
 * failure as a typed condition, never a crash.
 */
interface CrmConnector
{
    /** Read a call snapshot. May throw on a remote failure. */
    public function fetchCallContext(string $callId): CallContext;

    /**
     * Current field values of a CRM object, for the `from` side of a proposed
     * change. Empty array when the object does not exist (a create).
     *
     * @return array<string, mixed>
     */
    public function currentFields(string $objectType, ?string $objectId): array;

    /**
     * Execute an approved proposal against the system of record. Must be
     * idempotent on the proposal id (a retry never double-writes).
     */
    public function applyUpdate(CrmUpdateProposal $proposal): CrmUpdateResult;
}
