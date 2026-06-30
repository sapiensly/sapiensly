<?php

namespace App\Services\Connectors;

use App\Models\Tool;

/**
 * Single source of truth for the propose-don't-mutate rule on connector calls:
 * a non-`safe` write to an external system of record must be gated (proposed /
 * refused) instead of executing; reads and `safe`-marked writes run straight
 * through.
 *
 * Shared by the WorkflowEngine (which turns a gated decision into an
 * awaiting-approval proposal) and the use_tool MCP tool (which turns it into a
 * refusal carrying the blast radius), so the two can never drift on what counts
 * as a gated write.
 */
class ConnectorCallGate
{
    public function __construct(
        private readonly ConnectorActionResolver $resolver,
    ) {}

    /**
     * Resolve the tool's contract and decide whether this call must be gated.
     *
     * @param  bool  $gateApprovals  When false (e.g. executing an already-approved
     *                               action), writes are allowed through.
     */
    public function inspect(Tool $tool, bool $gateApprovals = true): ConnectorCallDecision
    {
        $contract = $this->resolver->resolve($tool);

        $mustGate = $gateApprovals && $contract->effect->isWrite() && ! $contract->safe;

        return new ConnectorCallDecision($contract, $mustGate);
    }
}
