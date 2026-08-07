<?php

namespace App\Support\Tracking;

/**
 * Whether somebody has arrived.
 *
 * The whole of geofencing on this platform is one distance and one hysteresis
 * rule, and both are here so they can be tested without a phone.
 */
final class Geofence
{
    /** Mean earth radius, metres. */
    private const EARTH_RADIUS_M = 6_371_008.8;

    /**
     * Great-circle distance in metres.
     *
     * Haversine rather than anything cleverer: over the distances a geofence
     * cares about (tens of metres to a few kilometres) the error against a
     * proper geodesic is centimetres, and the phone's own fix is out by five to
     * fifty metres. Precision beyond the input is a decoration.
     */
    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $φ1 = deg2rad($lat1);
        $φ2 = deg2rad($lat2);
        $Δφ = deg2rad($lat2 - $lat1);
        $Δλ = deg2rad($lng2 - $lng1);

        $a = sin($Δφ / 2) ** 2 + cos($φ1) * cos($φ2) * sin($Δλ / 2) ** 2;

        return self::EARTH_RADIUS_M * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Whether a reading is tight enough to say anything about this fence.
     *
     * "Within three kilometres" cannot tell you whether somebody is inside a
     * 150-metre circle. Answering anyway is how a visit gets stamped from the
     * depot — and it is a distinct answer from "outside", which is why the
     * caller asks this FIRST and leaves the state untouched rather than
     * treating an undecidable reading as the state it happened to start in.
     */
    public static function canDecide(?float $accuracy, float $radius): bool
    {
        return $accuracy === null || $accuracy <= $radius;
    }

    /**
     * The state a reading puts us in, given the state we were in.
     *
     * The asymmetry is the point. Arriving is judged against the radius;
     * LEAVING is judged against a wider one, so a phone sitting still at the
     * edge of the fence — which every phone does, because a fix wanders by tens
     * of metres while the person has not moved at all — does not fire arrive,
     * depart, arrive, depart for an hour. Without this the feature's first
     * outing is a workflow that ran forty times for one visit.
     *
     * Accuracy is used the same way: a reading the device says is worse than
     * the fence is wide cannot decide anything, so it leaves the state alone.
     */
    public static function nextState(
        bool $wasInside,
        float $distance,
        float $radius,
        ?float $accuracy = null,
    ): bool {
        if (! self::canDecide($accuracy, $radius)) {
            return $wasInside;
        }

        // A fifth again, floored at 25 metres — below that the hysteresis is
        // narrower than the fix itself and does no work.
        $exitRadius = max($radius * 1.2, $radius + 25);

        return $wasInside
            ? $distance <= $exitRadius
            : $distance <= $radius;
    }
}
