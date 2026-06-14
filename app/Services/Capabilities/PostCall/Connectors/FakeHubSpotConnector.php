<?php

namespace App\Services\Capabilities\PostCall\Connectors;

use App\Models\CrmUpdateProposal;
use App\Services\Capabilities\PostCall\Contracts\CrmConnector;
use App\Services\Capabilities\PostCall\Data\CallContext;
use App\Services\Capabilities\PostCall\Data\CrmUpdateResult;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * In-memory CRM connector used to build and verify capability #0001 before any
 * real OAuth/HubSpot wiring. It is the sandbox that makes behavioral verification
 * possible (you cannot seed test data into a production CRM) and it records every
 * write, so a test can assert the system of record stayed untouched after a
 * read+propose (propose-don't-mutate). Bound as a singleton so a test seeds the
 * same instance the services resolve.
 */
class FakeHubSpotConnector implements CrmConnector
{
    /** @var array<string, array<string, mixed>> callId => raw call data */
    private array $calls = [];

    /** @var array<string, array<string, mixed>> "type:id" => fields */
    private array $objects = [];

    /** @var array<string, string> proposalId => externalObjectId (idempotency ledger) */
    private array $applied = [];

    /** @var array<int, string> proposal ids in the order writes were attempted */
    public array $writeLog = [];

    /**
     * @param  array<string, mixed>  $data
     */
    public function seedCall(string $callId, array $data = []): void
    {
        $this->calls[$callId] = $data;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function seedObject(string $objectType, string $objectId, array $fields): void
    {
        $this->objects[$objectType.':'.$objectId] = $fields;
    }

    public function fetchCallContext(string $callId): CallContext
    {
        if (! isset($this->calls[$callId])) {
            throw new RuntimeException("Call {$callId} not found.");
        }

        $data = $this->calls[$callId];

        return new CallContext(
            callId: $callId,
            sourceFetchedAt: CarbonImmutable::now(),
            occurredAt: isset($data['occurred_at']) ? CarbonImmutable::parse($data['occurred_at']) : null,
            direction: $data['direction'] ?? null,
            durationSeconds: $data['duration_seconds'] ?? null,
            participants: $data['participants'] ?? [],
            transcript: $data['transcript'] ?? null,
            recordingUrl: $data['recording_url'] ?? null,
            associations: $data['associations'] ?? [],
        );
    }

    public function currentFields(string $objectType, ?string $objectId): array
    {
        if ($objectId === null) {
            return [];
        }

        return $this->objects[$objectType.':'.$objectId] ?? [];
    }

    public function applyUpdate(CrmUpdateProposal $proposal): CrmUpdateResult
    {
        $this->writeLog[] = $proposal->id;

        // Idempotent on the proposal id: a retry returns the same object and does
        // not mutate again.
        if (isset($this->applied[$proposal->id])) {
            return new CrmUpdateResult($proposal->id, 'applied', $this->applied[$proposal->id]);
        }

        $target = (array) $proposal->target;
        $objectType = (string) ($target['object_type'] ?? 'note');
        $objectId = $target['object_id'] ?? $objectType.'_'.substr($proposal->id, -8);

        $fields = $this->objects[$objectType.':'.$objectId] ?? [];
        foreach ((array) $proposal->changes as $change) {
            if (is_array($change) && isset($change['field'])) {
                $fields[$change['field']] = $change['to'] ?? null;
            }
        }
        $this->objects[$objectType.':'.$objectId] = $fields;

        $this->applied[$proposal->id] = (string) $objectId;

        return new CrmUpdateResult($proposal->id, 'applied', (string) $objectId);
    }

    /** Number of distinct writes that actually mutated the store. */
    public function appliedCount(): int
    {
        return count($this->applied);
    }
}
