import axios from 'axios';
import { onBeforeUnmount, ref } from 'vue';
import {
    considerFix,
    DEFAULT_LIMITS,
    type Ping,
    type TrackingLimits,
} from './tracking';

/**
 * Following somebody, only while they can see that it is happening.
 *
 * Three rules, and none of them is a nicety:
 *
 *  - it STARTS from a tap and never from a page loading. The difference
 *    between recording work and watching a person is whether they agreed, and
 *    an app that begins the moment it opens has not asked;
 *  - it is VISIBLE the whole time it runs — see TrackingBar — because consent
 *    somebody has forgotten they gave is not consent;
 *  - it STOPS, immediately, on their say-so, and the server refuses further
 *    pings for a stopped session so a stuck client cannot override that.
 *
 * What it cannot do is run in the background: there is no such API on the web.
 * The gap that leaves is recorded as a gap rather than papered over.
 */
export function useTracking(appSlug: string) {
    const active = ref(false);
    const sessionId = ref<string | null>(null);
    const pending = ref(0);
    /** Set when a fix could not be had at all — refused, or no signal. */
    const problem = ref<string | null>(null);

    let watchId: number | null = null;
    let last: Ping | null = null;
    let queue: Ping[] = [];
    let flushing = false;
    let limits: TrackingLimits = DEFAULT_LIMITS;

    async function start(recordId?: string): Promise<boolean> {
        if (active.value) return true;
        problem.value = null;

        if (typeof navigator === 'undefined' || !navigator.geolocation) {
            problem.value = 'unsupported';

            return false;
        }

        try {
            const { data } = await axios.post(`/r/${appSlug}/tracking/start`, {
                ...(recordId ? { record_id: recordId } : {}),
            });

            sessionId.value = data.session_id;
            limits = {
                minIntervalSeconds: data.limits?.min_interval_s ?? 30,
                minDistanceMeters: data.limits?.min_distance_m ?? 50,
            };
        } catch {
            // Refused by the server: this app does not track. Nothing was
            // started, and nothing should look as though it was.
            problem.value = 'refused';

            return false;
        }

        watchId = navigator.geolocation.watchPosition(
            (position) => {
                const ping = considerFix(
                    last,
                    {
                        lat: Number(position.coords.latitude.toFixed(6)),
                        lng: Number(position.coords.longitude.toFixed(6)),
                        accuracy: Number.isFinite(position.coords.accuracy)
                            ? Math.round(position.coords.accuracy)
                            : null,
                        at: new Date(),
                    },
                    limits,
                );

                if (ping === null) return;

                last = ping;
                queue.push(ping);
                pending.value = queue.length;
                void flush();
            },
            () => {
                // Permission withdrawn mid-trail, or no fix. Said rather than
                // swallowed: a trail that quietly stopped collecting is worse
                // than one that says it did.
                problem.value = 'denied';
            },
            { enableHighAccuracy: true, maximumAge: 10_000, timeout: 30_000 },
        );

        active.value = true;

        return true;
    }

    /**
     * Send what has piled up.
     *
     * Batched because a fix every thirty seconds is a request every thirty
     * seconds, and a technician's phone spends the day on a signal that comes
     * and goes. The queue is only cleared once the server has taken it — a
     * failed flush keeps its points and tries again on the next fix.
     */
    async function flush(): Promise<void> {
        if (flushing || queue.length === 0 || sessionId.value === null) return;

        flushing = true;
        const batch = queue.slice(0, 200);

        try {
            await axios.post(`/r/${appSlug}/tracking/pings`, {
                session_id: sessionId.value,
                pings: batch,
            });

            queue = queue.slice(batch.length);
            pending.value = queue.length;
        } catch (error) {
            // 409 means the session was stopped — by this person on another
            // device, or by the pruner. Keeping the points would be holding
            // data for a session that is over.
            if (
                (error as { response?: { status?: number } })?.response
                    ?.status === 409
            ) {
                queue = [];
                pending.value = 0;
                await stop();
            }
        } finally {
            flushing = false;
        }
    }

    async function stop(): Promise<void> {
        if (watchId !== null && typeof navigator !== 'undefined') {
            navigator.geolocation.clearWatch(watchId);
            watchId = null;
        }

        const id = sessionId.value;
        active.value = false;
        sessionId.value = null;
        last = null;
        queue = [];
        pending.value = 0;

        if (id === null) return;

        await axios
            .post(`/r/${appSlug}/tracking/stop`, { session_id: id })
            .catch(() => undefined);
    }

    // Leaving the page stops the watch, which the browser would do anyway —
    // but the SESSION is deliberately left open, because closing a tab is not
    // somebody saying they are done and the trail resumes when they come back.
    onBeforeUnmount(() => {
        if (watchId !== null && typeof navigator !== 'undefined') {
            navigator.geolocation.clearWatch(watchId);
            watchId = null;
        }
    });

    return { active, sessionId, pending, problem, start, stop };
}
