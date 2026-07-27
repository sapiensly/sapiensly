<?php

namespace App\Services\Chatbots;

use App\Facades\TenantCache;
use App\Models\User;

/**
 * Whether anyone from this organization is actually watching the inbox right now.
 *
 * This is the fact phase 1 deliberately did without. A bot that offers to fetch
 * a person is only telling the truth if a person is there, and no amount of
 * configuration can answer that — an "office hours" setting says when someone is
 * SUPPOSED to be there, which is a different question and the one that produces
 * "un agente te atenderá en seguida" at 3am on a holiday.
 *
 * So presence is measured, not declared: an operator with the inbox open says so
 * every {@see self::HEARTBEAT_SECONDS}, and the answer expires on its own. It
 * fails closed in every direction — no heartbeat, no Redis, no scope, no cached
 * value — because every failure mode of "is someone there?" must resolve to NO.
 * A false yes is a promise broken in front of a customer; a false no costs an
 * offer the visitor never knew about.
 */
class OperatorPresence
{
    /** How often a watching operator re-announces itself. */
    public const HEARTBEAT_SECONDS = 20;

    /**
     * How long one heartbeat vouches for someone.
     *
     * Three beats, so a slow request or a browser that throttled a background
     * tab does not read as "everyone left". Short enough that a closed laptop
     * stops promising a person within a minute.
     */
    private const TTL_SECONDS = 60;

    private const KEY = 'chatbots:operators-online';

    /**
     * Record that this user is watching. Called by the inbox on a timer.
     */
    public function touch(User $operator): void
    {
        $watching = $this->watching($operator->organization_id, $operator->id);
        $watching[(string) $operator->id] = now()->getTimestamp();

        $this->cacheFor($operator->organization_id, $operator->id)
            ->put(self::KEY, $this->fresh($watching), self::TTL_SECONDS);
    }

    /**
     * Stop vouching for this user immediately — they closed the inbox, or
     * released their last conversation and walked away.
     */
    public function forget(User $operator): void
    {
        $watching = $this->watching($operator->organization_id, $operator->id);
        unset($watching[(string) $operator->id]);

        $cache = $this->cacheFor($operator->organization_id, $operator->id);

        $watching === []
            ? $cache->forget(self::KEY)
            : $cache->put(self::KEY, $watching, self::TTL_SECONDS);
    }

    /**
     * Is at least one person watching this owner's inbox?
     *
     * Takes the owner explicitly rather than reading the ambient tenant scope:
     * this is asked while answering a stranger's turn, and "which organization
     * is someone watching for" must be the bot's, not whatever scope happens to
     * be bound to the request.
     */
    public function anyoneWatching(?string $organizationId, ?int $userId = null): bool
    {
        return $this->fresh($this->watching($organizationId, $userId)) !== [];
    }

    /**
     * @return array<string, int> operator id => last heartbeat timestamp
     */
    private function watching(?string $organizationId, ?int $userId): array
    {
        try {
            $stored = $this->cacheFor($organizationId, $userId)->get(self::KEY, []);
        } catch (\Throwable) {
            // No tenant scope, or no cache at all. Both mean we cannot know, and
            // "cannot know" is NO — see the class docblock.
            return [];
        }

        return is_array($stored) ? $stored : [];
    }

    private function cacheFor(?string $organizationId, ?int $userId): mixed
    {
        return TenantCache::forOwner($organizationId, $userId);
    }

    /**
     * Drop entries whose heartbeat has aged out.
     *
     * The whole map shares one TTL, so a single operator still beating keeps the
     * key alive for everyone in it — including someone who left ten minutes ago.
     * Without this filter the last person to leave would keep the organization
     * looking staffed for as long as anyone else stayed.
     *
     * @param  array<string, int>  $watching
     * @return array<string, int>
     */
    private function fresh(array $watching): array
    {
        $cutoff = now()->getTimestamp() - self::TTL_SECONDS;

        return array_filter(
            $watching,
            fn ($at) => is_int($at) && $at >= $cutoff,
        );
    }
}
