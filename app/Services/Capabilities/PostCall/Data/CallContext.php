<?php

namespace App\Services\Capabilities\PostCall\Data;

use Carbon\CarbonImmutable;

/**
 * Snapshot of a call read from the CRM (capability #0001's read output). Fields
 * beyond the id are optional — external data is partial and may be missing (e.g.
 * an untranscribed call) — and consumers must degrade gracefully rather than
 * assume completeness. See docs/capabilities/0001-hubspot-post-call-agent.md §2.1.
 */
final readonly class CallContext
{
    /**
     * @param  array<int, array<string, mixed>>  $participants
     * @param  array<string, string|null>  $associations  contact_id / company_id / deal_id
     */
    public function __construct(
        public string $callId,
        public CarbonImmutable $sourceFetchedAt,
        public ?CarbonImmutable $occurredAt = null,
        public ?string $direction = null,
        public ?int $durationSeconds = null,
        public array $participants = [],
        public ?string $transcript = null,
        public ?string $recordingUrl = null,
        public array $associations = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'call_id' => $this->callId,
            'source_fetched_at' => $this->sourceFetchedAt->toIso8601String(),
            'occurred_at' => $this->occurredAt?->toIso8601String(),
            'direction' => $this->direction,
            'duration_seconds' => $this->durationSeconds,
            'participants' => $this->participants,
            'transcript' => $this->transcript,
            'recording_url' => $this->recordingUrl,
            'associations' => $this->associations,
        ];
    }
}
