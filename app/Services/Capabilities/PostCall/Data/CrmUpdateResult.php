<?php

namespace App\Services\Capabilities\PostCall\Data;

/**
 * Outcome of executing a proposal against the CRM (capability #0001's write
 * result). See docs/capabilities/0001-hubspot-post-call-agent.md §2.3.
 */
final readonly class CrmUpdateResult
{
    public function __construct(
        public string $proposalId,
        public string $status,          // 'applied' | 'rejected' | 'failed'
        public ?string $externalObjectId = null,
        public ?string $error = null,
    ) {}
}
