<?php

namespace App\Support\Tracking;

use App\Support\Offline\OfflinePolicy;

/**
 * What an app is allowed to follow, as its owner said it.
 *
 * OFF unless somebody turned it on — the exact reverse of `settings.offline`,
 * and for the reverse reason. Offline defaults on because an app that stops
 * working in a basement is a broken app; tracking defaults off because an app
 * that follows people nobody agreed to follow is not a feature, it is an
 * incident. Absence means no, and "no" is what a manifest that never heard of
 * this says.
 *
 * Read here and nowhere else, so the shape has one owner. Mirrors
 * {@see OfflinePolicy}.
 */
final class TrackingPolicy
{
    private function __construct(
        public readonly bool $enabled,
        public readonly int $minIntervalSeconds,
        public readonly int $minDistanceMeters,
        public readonly int $radiusMeters,
        public readonly int $retainDays,
    ) {}

    /**
     * @param  array<string, mixed>  $manifest
     */
    public static function fromManifest(array $manifest): self
    {
        $raw = $manifest['settings']['tracking'] ?? null;

        if (! is_array($raw) || ($raw['enabled'] ?? false) !== true) {
            return new self(false, 30, 50, 150, 30);
        }

        return new self(
            enabled: true,
            // Floored, not just defaulted. A manifest asking for a fix every
            // second would flatten a phone's battery in an afternoon and fill a
            // table with rows saying the same thing, and the person carrying it
            // has no way to argue.
            minIntervalSeconds: max(15, (int) ($raw['min_interval_s'] ?? 30)),
            minDistanceMeters: max(10, (int) ($raw['min_distance_m'] ?? 50)),
            // Below about fifty metres a fence is narrower than a phone's own
            // fix, so it would fire on a person standing still.
            radiusMeters: max(50, (int) ($raw['geofence_radius_m'] ?? 150)),
            // Capped as well as defaulted: this is the promise that a trail does
            // not outlive the work it documents.
            retainDays: min(365, max(1, (int) ($raw['retain_days'] ?? 30))),
        );
    }
}
