<?php

namespace App\Services\Builder;

use App\Models\BuilderConversation;
use App\Support\Tenancy\TenantCache;

/**
 * Cooperative "stop build" signal for a builder conversation. The user hits
 * Detener; this raises a short-lived tenant-scoped flag that every moving part
 * of the build machinery checks: the streaming loop (which finalizes the turn
 * early, banking whatever the proposal accumulator already holds), the
 * plan-driven / autonomous / timeout-resume chain starters (which refuse to
 * queue the next turn), and auto-queued jobs picked up after the click (which
 * abort before spending a token). The flag survives until the user speaks
 * again — sending a new message clears it — so a stopped chain stays stopped.
 *
 * TenantCache scoped explicitly to the conversation's owner: never a shared
 * key, works identically in HTTP and queue contexts.
 */
class BuilderCancellation
{
    /** Generous enough to outlive any queued auto-turn (turn cap is 300s). */
    private const TTL_SECONDS = 1800;

    public function __construct(private readonly TenantCache $cache) {}

    public function request(BuilderConversation $conversation): void
    {
        try {
            $this->scoped($conversation)->put($this->key($conversation), now()->toIso8601String(), self::TTL_SECONDS);
        } catch (\Throwable) {
            // Cache down → the stop degrades to "the chain ends on its own
            // bounded budget"; never an error in the user's face.
        }
    }

    public function requested(BuilderConversation $conversation): bool
    {
        try {
            return $this->scoped($conversation)->get($this->key($conversation)) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    public function clear(BuilderConversation $conversation): void
    {
        try {
            $this->scoped($conversation)->forget($this->key($conversation));
        } catch (\Throwable) {
            // Best-effort — the TTL clears it regardless.
        }
    }

    private function key(BuilderConversation $conversation): string
    {
        return "builder:cancel:{$conversation->id}";
    }

    private function scoped(BuilderConversation $conversation): TenantCache
    {
        return $this->cache->forOwner($conversation->organization_id, $conversation->user_id);
    }
}
