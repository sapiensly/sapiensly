<?php

namespace App\Services\Chat\Actions;

/**
 * Maps a synthesized `action_type` to the handler that can execute it.
 *
 * v1 knows only `manual`; every proposal normalizes to it (see
 * {@see self::normalizeType()}). Register real handlers here as they are wired —
 * the synthesizer's validation and the executor both route through this class, so
 * nothing else changes when the registry grows.
 */
class ActionRegistry
{
    /** @var array<string, ActionHandler> */
    private array $handlers = [];

    public function __construct()
    {
        $this->register(new ManualAction);
    }

    public function register(ActionHandler $handler): void
    {
        $this->handlers[$handler->key()] = $handler;
    }

    public function knows(string $actionType): bool
    {
        return isset($this->handlers[$actionType]);
    }

    /**
     * Resolve the handler for an action type, falling back to the manual handler
     * for anything not (yet) wired.
     */
    public function resolve(string $actionType): ActionHandler
    {
        return $this->handlers[$actionType] ?? $this->handlers[ManualAction::KEY];
    }

    /**
     * The effective action type to persist: a known type as-is, otherwise
     * `manual` (the proposal is described but not wired to a real workflow).
     */
    public function normalizeType(string $actionType): string
    {
        return $this->knows($actionType) ? $actionType : ManualAction::KEY;
    }
}
