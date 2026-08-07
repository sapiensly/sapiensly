<?php

namespace App\Support\Broadcasting;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Broadcasting that cannot stall the work it decorates.
 *
 * Live updates are cosmetic. The record is already written, the builder message
 * is already persisted, and a page refresh recovers either. Nothing here is
 * worth waiting on — and yet waiting is exactly what a dead broadcaster costs.
 *
 * A refused connection to Reverb does NOT fail fast: it takes about THIRTY
 * SECONDS. Catching the exception was never the point; the callers all caught
 * it and carried on, having already paid. Measured live: a builder turn burned
 * its whole 300s budget on a dozen of these and died telling the user their
 * request was too big, and seeding three demo records took 90.1 seconds —
 * three writes, thirty seconds each, no work in between.
 *
 * So the FIRST failure silences broadcasting for a minute, process-wide. The
 * flag is a platform value rather than a tenant one (Cache, not TenantCache):
 * a broadcaster is either up or down for everybody. The cost of being wrong is
 * up to a minute of missing live updates on a page that would have refreshed
 * correctly anyway — against a bulk action over fifty rows taking twenty-five
 * minutes, which is what the alternative measured.
 */
final class SafeBroadcast
{
    /**
     * How long one failure silences the rest.
     *
     * Long enough that a burst of writes pays the 30s once instead of once
     * each; short enough that a broadcaster coming back is noticed while
     * somebody is still looking at the page.
     */
    private const COOLDOWN_SECONDS = 60;

    private const KEY = 'broadcaster:unavailable';

    /** Set when the cache itself is unreachable, so we stop asking it too. */
    private static bool $cacheUnavailable = false;

    public static function dispatch(Closure $broadcast): void
    {
        if (self::silenced()) {
            return;
        }

        try {
            $broadcast();
        } catch (Throwable $e) {
            self::silence();

            Log::warning('Broadcast failed; live updates paused', [
                'error' => $e->getMessage(),
                'seconds' => self::COOLDOWN_SECONDS,
            ]);
        }
    }

    /** Let a caller re-arm — used by tests, and by anything that knows better. */
    public static function resume(): void
    {
        self::$cacheUnavailable = false;

        try {
            Cache::forget(self::KEY);
        } catch (Throwable) {
            // Nothing to clear if the cache is gone.
        }
    }

    private static function silenced(): bool
    {
        if (self::$cacheUnavailable) {
            return false;
        }

        try {
            return Cache::get(self::KEY) === true;
        } catch (Throwable) {
            // A cache we cannot read must not become a second thing that
            // blocks writes. Fall through and let the broadcast try.
            self::$cacheUnavailable = true;

            return false;
        }
    }

    private static function silence(): void
    {
        try {
            Cache::put(self::KEY, true, self::COOLDOWN_SECONDS);
        } catch (Throwable) {
            self::$cacheUnavailable = true;
        }
    }
}
