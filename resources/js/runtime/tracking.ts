/**
 * Following where somebody went, while they are looking at the app.
 *
 * THE CONSTRAINT THIS IS BUILT AROUND: the web has no background geolocation.
 * `watchPosition` runs while the page is alive and stops when the phone locks,
 * the tab is buried or the browser decides to save battery. There is no API
 * that changes this, and no amount of service worker gets it back.
 *
 * That is not a footnote, it is the design. A trail with a silent hole in it
 * is read as a straight line — the technician "drove directly" across a
 * building — so a gap is recorded AS a gap, the bar says tracking only runs
 * with the app open, and the feature pairs with `keep_awake` on the page that
 * uses it.
 */
export interface Ping {
    lat: number;
    lng: number;
    accuracy: number | null;
    at: string;
    /** True when enough time passed since the last fix that the line between
     *  them is not a path anybody travelled. */
    gap: boolean;
}

export interface TrackingLimits {
    /** Never store two points closer together in time than this. */
    minIntervalSeconds: number;
    /** …or closer together in space. Standing still should cost nothing. */
    minDistanceMeters: number;
}

export const DEFAULT_LIMITS: TrackingLimits = {
    minIntervalSeconds: 30,
    minDistanceMeters: 50,
};

/**
 * How long a silence has to be before the line across it stops meaning
 * anything. Three missed intervals: long enough not to flag ordinary jitter,
 * short enough that a locked phone shows up as what it was.
 */
const GAP_FACTOR = 3;

export function distanceMeters(
    lat1: number,
    lng1: number,
    lat2: number,
    lng2: number,
): number {
    const R = 6_371_008.8;
    const toRad = (d: number) => (d * Math.PI) / 180;
    const dLat = toRad(lat2 - lat1);
    const dLng = toRad(lng2 - lng1);
    const a =
        Math.sin(dLat / 2) ** 2 +
        Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) ** 2;

    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

/**
 * Whether this fix is worth keeping, and what it means.
 *
 * Pure, because it is the whole of the policy: a phone reports a position
 * several times a second, and storing all of them would be a row every 200ms
 * per technician for somebody who has not moved. Returns null for a fix that
 * says nothing new.
 */
export function considerFix(
    previous: Ping | null,
    fix: { lat: number; lng: number; accuracy: number | null; at: Date },
    limits: TrackingLimits = DEFAULT_LIMITS,
): Ping | null {
    const at = fix.at.toISOString();

    if (previous === null) {
        return {
            lat: fix.lat,
            lng: fix.lng,
            accuracy: fix.accuracy,
            at,
            gap: false,
        };
    }

    const elapsed = (fix.at.getTime() - Date.parse(previous.at)) / 1000;
    if (elapsed < 0) {
        // A clock that went backwards. Keeping it would put the trail out of
        // order, and the trail's only job is to be in order.
        return null;
    }

    const moved = distanceMeters(previous.lat, previous.lng, fix.lat, fix.lng);

    // A gap always gets recorded, even if the person came back to the same
    // spot: the silence IS the information — it says the app was closed, not
    // that they stood still.
    const gap = elapsed > limits.minIntervalSeconds * GAP_FACTOR;

    if (!gap && elapsed < limits.minIntervalSeconds) {
        return null;
    }

    if (!gap && moved < limits.minDistanceMeters) {
        return null;
    }

    return { lat: fix.lat, lng: fix.lng, accuracy: fix.accuracy, at, gap };
}
