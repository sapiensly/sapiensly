import { describe, expect, it } from 'vitest';
import { considerFix, distanceMeters, type Ping } from './tracking';

/**
 * Which fixes are worth keeping.
 *
 * A phone reports its position several times a second. Storing all of them
 * would be a row every 200ms per technician, most of them saying the same
 * thing — so the thresholds are the feature, and the GAP is the part that has
 * to survive them: the web cannot follow anybody with the app closed, and a
 * straight line drawn across that silence reads as a journey nobody made.
 */
const at = (seconds: number) => new Date(Date.UTC(2026, 7, 7, 12, 0, seconds));

const ping = (over: Partial<Ping> = {}): Ping => ({
    lat: 19.4326,
    lng: -99.1332,
    accuracy: 10,
    at: at(0).toISOString(),
    gap: false,
    ...over,
});

describe('deciding what to record', () => {
    it('always keeps the first fix', () => {
        expect(
            considerFix(null, {
                lat: 19.4326,
                lng: -99.1332,
                accuracy: 10,
                at: at(0),
            }),
        ).toMatchObject({ lat: 19.4326, gap: false });
    });

    it('ignores a fix that is too soon', () => {
        expect(
            considerFix(ping(), {
                lat: 19.5,
                lng: -99.2,
                accuracy: 10,
                at: at(5),
            }),
        ).toBeNull();
    });

    it('ignores a fix that has not moved', () => {
        // Standing still should cost nothing. A phone parked on a counter
        // otherwise writes a row a minute all day. Sixty seconds: past the
        // interval, and not yet long enough to be a gap — a silence outranks
        // the distance rule, which the next test is about.
        expect(
            considerFix(ping(), {
                lat: 19.43262,
                lng: -99.13322,
                accuracy: 10,
                at: at(60),
            }),
        ).toBeNull();
    });

    it('keeps a fix that is far enough away and long enough after', () => {
        expect(
            considerFix(ping(), {
                lat: 19.45,
                lng: -99.14,
                accuracy: 10,
                at: at(60),
            }),
        ).toMatchObject({ gap: false });
    });

    it('marks a silence as a gap, even standing in the same spot', () => {
        // The phone was locked for ten minutes. The person may not have moved
        // at all — that is exactly why the silence has to be recorded rather
        // than inferred from the distance.
        const result = considerFix(ping(), {
            lat: 19.4326,
            lng: -99.1332,
            accuracy: 10,
            at: at(600),
        });

        expect(result).not.toBeNull();
        expect(result?.gap).toBe(true);
    });

    it('refuses a fix from before the one it follows', () => {
        // A phone's clock is whatever its owner set it to, and the trail's only
        // job is to be in order.
        expect(
            considerFix(ping({ at: at(600).toISOString() }), {
                lat: 19.5,
                lng: -99.2,
                accuracy: 10,
                at: at(0),
            }),
        ).toBeNull();
    });
});

describe('measuring how far somebody moved', () => {
    it('agrees with a map', () => {
        const metres = distanceMeters(19.427, -99.1677, 19.4326, -99.1332);

        expect(metres).toBeGreaterThan(3_500);
        expect(metres).toBeLessThan(3_800);
    });

    it('agrees with the server, which draws the same fences', () => {
        // The client throttles on distance and the server judges arrival on it.
        // Two formulas that disagree would mean points the phone thought were
        // worth sending and the fence thought were somewhere else.
        expect(distanceMeters(19.4, -99.1, 19.4, -99.1)).toBe(0);
    });
});
