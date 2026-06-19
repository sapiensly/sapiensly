<?php

namespace App\Services\Workflows;

use RuntimeException;

/**
 * Thrown when a real run reaches a gated write (a non-`safe` connector write):
 * the engine emits a proposal and STOPS rather than mutating the external system
 * (propose-don't-mutate, FR-5.3/9.3). This is the emit-then-stop wedge — no
 * durable resume; an approver later executes the proposed action in isolation.
 */
class WorkflowAwaitingApprovalException extends RuntimeException
{
    /**
     * @param  array{step_id: string, effect: string, action: array<string, mixed>, preview: string}  $proposal
     */
    public function __construct(public readonly array $proposal)
    {
        parent::__construct('Workflow paused awaiting approval.');
    }
}
