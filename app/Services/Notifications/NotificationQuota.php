<?php

namespace App\Services\Notifications;

use App\Facades\TenantCache;
use App\Models\App;

/**
 * The ceiling on outbound notifications for one organization, per hour.
 *
 * This exists because of a chain that is easy to miss: a public portal lets a
 * stranger write a record, a `record.created` trigger runs a workflow, and that
 * workflow can send email. Nothing in that chain is wrong on its own, and
 * together they are a spam cannon pointed at the platform's sending reputation.
 * The quota is what makes the blast radius finite whatever the manifest says.
 *
 * Counted per organization rather than per app or per workflow: an attacker who
 * can trigger one workflow can usually trigger several, so a per-workflow limit
 * would just be multiplied by however many exist.
 */
class NotificationQuota
{
    /** Sends allowed per organization per hour. */
    public const HOURLY_LIMIT = 200;

    /** Destinations one step may address in a single run. */
    public const MAX_RECIPIENTS_PER_STEP = 20;

    private const TTL_SECONDS = 3600;

    /**
     * Claim capacity for `$wanted` sends, returning how many were granted.
     * Partial grants are deliberate: sending to the first eight of ten
     * recipients and reporting the shortfall beats sending to none.
     */
    public function claim(App $app, int $wanted): int
    {
        if ($wanted <= 0) {
            return 0;
        }

        $cache = $this->bucket($app);
        $key = $this->key();

        // add() only writes when the key is absent, so the window starts on the
        // first send of the hour and expires on its own — no reset job.
        $cache->add($key, 0, self::TTL_SECONDS);

        $used = (int) $cache->get($key, 0);
        $granted = max(0, min($wanted, self::HOURLY_LIMIT - $used));

        if ($granted > 0) {
            $cache->increment($key, $granted);
        }

        return $granted;
    }

    public function remaining(App $app): int
    {
        return max(0, self::HOURLY_LIMIT - (int) $this->bucket($app)->get($this->key(), 0));
    }

    /**
     * The counter is scoped to the APP'S OWNER, explicitly — not to the ambient
     * request scope. A workflow can run for whoever triggered it (a member, a
     * queue worker, an anonymous portal visitor with no scope at all), and the
     * quota must land in the same bucket every time regardless. Explicit scoping
     * also means the step can never fail merely because the cache had no scope
     * to infer.
     */
    private function bucket(App $app): \App\Support\Tenancy\TenantCache
    {
        return TenantCache::forOwner($app->organization_id, $app->user_id);
    }

    /**
     * The hour is part of the key, so the window rolls without a sweeper and a
     * clock skew can only ever cost capacity, never grant extra.
     */
    private function key(): string
    {
        return 'notifications:sent:'.gmdate('YmdH');
    }
}
