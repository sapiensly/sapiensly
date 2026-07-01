<?php

namespace App\Support\Chat;

/**
 * Mutable, turn-scoped collector for the agent consultations made while
 * streaming one assistant message. The ConsultAgentTool appends to it during
 * the stream; ChatAiService reads it at finalization to persist the
 * consultation cards onto the message (consultation_context).
 */
class ConsultationLog
{
    /** @var list<array<string, mixed>> */
    private array $entries = [];

    /**
     * @param  array<string, mixed>  $entry
     */
    public function add(array $entry): void
    {
        $this->entries[] = $entry;
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * Number of consultations recorded so far this turn — used to cap how many
     * times one turn may consult other agents.
     */
    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->entries;
    }
}
