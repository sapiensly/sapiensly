<?php

namespace App\Services\Runtime;

/**
 * Per-turn accumulator for the runtime agent's proposed writes (builder power #3
 * gate). The propose_* tools record into this companion instead of executing;
 * the service reads it at stream end and, if non-empty, marks the assistant
 * message as an action_proposal awaiting human approval (Rule 2 —
 * propose-don't-mutate). Nothing here touches the system of record.
 */
class ProposedActions
{
    /** @var list<array{action: array<string, mixed>, preview: string}> */
    private array $items = [];

    /**
     * @param  array<string, mixed>  $action  an AppActionExecutor-shaped action (type, object_id, values, ...)
     */
    public function add(array $action, string $preview): void
    {
        $this->items[] = ['action' => $action, 'preview' => $preview];
    }

    /**
     * @return list<array{action: array<string, mixed>, preview: string}>
     */
    public function all(): array
    {
        return $this->items;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
