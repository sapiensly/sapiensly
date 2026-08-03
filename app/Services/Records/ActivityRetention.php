<?php

namespace App\Services\Records;

use App\Models\App;
use App\Models\Organization;

/**
 * How long an activity trail is kept, and what that means in dates.
 *
 * The periods are fixed rather than free-form. "How long do you keep audit
 * records" has a small set of real answers — a month while you are working, a
 * year for most policies, three or ten for a regulated one — and a text box
 * invites 45 or 400, numbers nobody chose on purpose and every reader has to
 * interpret.
 *
 * The default is OFF. Partly cost — a log of everything with no ceiling is the
 * most expensive table in the system by year three, and almost nobody changes a
 * default — but mostly because an activity trail records who did what, and
 * deciding to keep that is a business's call about its own people, its policy
 * and its auditors. It is not something a platform should start doing to them
 * because nobody said no.
 */
class ActivityRetention
{
    /**
     * Months a trail is kept, in the order they are offered. Zero is "do not
     * keep one", which is a period like any other rather than a separate flag:
     * "how long do we keep it" already has room for "we don't", and one column
     * with one meaning beats two that can disagree about whether a trail is on
     * but kept for no time.
     */
    public const PERIODS = [0, 1, 6, 12, 36, 120];

    public const DEFAULT_MONTHS = 0;

    /**
     * Beyond this, deleting rows one by one stops being maintenance and starts
     * being an incident. A tenant asking for ten years should be on partitions
     * that get detached, not on a DELETE that walks millions of rows — so the
     * pruner says so rather than quietly trying.
     */
    public const BATCHED_DELETE_CEILING = 500_000;

    /** Whether this app records a trail at all. */
    public function isEnabled(App $app): bool
    {
        return $this->monthsFor($app) > 0;
    }

    public function monthsFor(App $app): int
    {
        $own = $app->activity_retention_months;
        if (is_int($own) && $this->isKnown($own)) {
            return $own;
        }

        $organization = $app->organization_id === null
            ? null
            : Organization::query()->find($app->organization_id);

        $shared = $organization?->activity_retention_months;

        return is_int($shared) && $this->isKnown($shared)
            ? $shared
            // An unrecognised number — set before a period was retired, or by
            // hand — reads as the default rather than as "keep for ever".
            : self::DEFAULT_MONTHS;
    }

    /** Anything written before this is past its keeping. */
    public function cutoffFor(App $app): \DateTimeImmutable
    {
        return new \DateTimeImmutable('-'.$this->monthsFor($app).' months');
    }

    private function isKnown(int $months): bool
    {
        return in_array($months, self::PERIODS, true);
    }
}
