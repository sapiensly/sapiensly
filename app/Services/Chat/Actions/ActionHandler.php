<?php

namespace App\Services\Chat\Actions;

use App\Models\Chat;

/**
 * An executable action that an ActionCard can run when the user clicks Execute.
 *
 * v1 ships only {@see ManualAction}; real flow/workflow-backed handlers plug in
 * here later without touching the executor or the frontend.
 */
interface ActionHandler
{
    /** The action_type this handler is registered under. */
    public function key(): string;

    /**
     * Execute the proposed action with the (server-validated) payload.
     *
     * @param  array<string, mixed>  $payload  the action_payload from the proposal
     * @return array{summary: string, data?: array<string, mixed>}
     */
    public function execute(Chat $chat, array $payload): array;
}
