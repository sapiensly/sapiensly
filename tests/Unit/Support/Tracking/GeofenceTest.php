<?php

use App\Support\Tracking\Geofence;

/**
 * Whether somebody has arrived.
 *
 * The distance is arithmetic and barely worth a test. The HYSTERESIS is the
 * whole feature: a phone sitting still at the edge of a fence wanders by tens
 * of metres because that is what a fix does, and a rule with one radius fires
 * arrive, depart, arrive, depart for an hour. Without this the feature's first
 * outing is a workflow that ran forty times for one visit and forty messages
 * to a customer.
 */
it('measures a distance somebody could check on a map', function () {
    // Ángel de la Independencia to the Zócalo, which is about 3 km.
    $metres = Geofence::distanceMeters(19.427, -99.1677, 19.4326, -99.1332);

    expect($metres)->toBeGreaterThan(3_500)
        ->and($metres)->toBeLessThan(3_800);
});

it('is symmetric and zero at a point', function () {
    expect(Geofence::distanceMeters(19.4, -99.1, 19.4, -99.1))->toBe(0.0)
        ->and(round(Geofence::distanceMeters(19.4, -99.1, 19.5, -99.2), 3))
        ->toBe(round(Geofence::distanceMeters(19.5, -99.2, 19.4, -99.1), 3));
});

it('arrives at the radius and leaves past a wider one', function () {
    // Outside, coming in.
    expect(Geofence::nextState(false, 200, 150))->toBeFalse()
        ->and(Geofence::nextState(false, 140, 150))->toBeTrue();

    // Inside, drifting. 170 m is outside the fence and inside the exit ring —
    // this is the reading that would otherwise fire "departed" while somebody
    // stands in the customer's doorway.
    expect(Geofence::nextState(true, 170, 150))->toBeTrue()
        ->and(Geofence::nextState(true, 300, 150))->toBeFalse();
});

it('keeps the exit ring meaningful on a small fence', function () {
    // A fifth of 50 m is 10 m, which is narrower than any phone's fix. The
    // floor is what stops a small fence from behaving as if there were no
    // hysteresis at all.
    expect(Geofence::nextState(true, 70, 50))->toBeTrue()
        ->and(Geofence::nextState(true, 80, 50))->toBeFalse();
});

it('decides nothing on a reading too vague to decide with', function () {
    // "Within three kilometres" cannot tell you whether somebody is inside a
    // 150 m fence. Answering anyway is how a visit gets stamped from the depot.
    expect(Geofence::nextState(false, 10, 150, accuracy: 3_000))->toBeFalse()
        ->and(Geofence::nextState(true, 5_000, 150, accuracy: 3_000))->toBeTrue();
});

it('trusts a reading at least as tight as the fence', function () {
    expect(Geofence::nextState(false, 100, 150, accuracy: 20))->toBeTrue();
});
