import axios from 'axios';

/**
 * Asking a browser for permission to reach it when the app is closed.
 *
 * The one thing an app could not do: everything else here works because
 * somebody is looking at the screen. A technician handed a job while driving is
 * not, and an offline runtime that opens in a basement is no use if nothing
 * tells them to go down there.
 *
 * Permission is asked for from a TAP and never on load. A page that asks the
 * moment it opens gets refused by people who have not yet decided they want
 * this, and a refusal in Chrome is close to permanent — there is no second
 * chance to ask, only a settings screen nobody visits.
 */
export type PushState =
    | 'unsupported'
    | 'unconfigured'
    | 'denied'
    | 'off'
    | 'on';

interface KeyResponse {
    configured: boolean;
    key: string | null;
}

function supported(): boolean {
    return (
        typeof navigator !== 'undefined' &&
        'serviceWorker' in navigator &&
        typeof window !== 'undefined' &&
        'PushManager' in window &&
        'Notification' in window
    );
}

/**
 * The VAPID key as the browser wants it: raw bytes, not base64url.
 *
 * Typed as an ArrayBuffer rather than a view, because `applicationServerKey`
 * accepts both and only one of them satisfies a TypeScript that has learned
 * about SharedArrayBuffer.
 */
export function decodeKey(base64url: string): ArrayBuffer {
    const padded = (base64url + '='.repeat((4 - (base64url.length % 4)) % 4))
        .replace(/-/g, '+')
        .replace(/_/g, '/');

    const raw = atob(padded);
    const bytes = new Uint8Array(new ArrayBuffer(raw.length));
    for (let i = 0; i < raw.length; i += 1) {
        bytes[i] = raw.charCodeAt(i);
    }

    return bytes.buffer;
}

/**
 * Where this device stands, without asking for anything.
 *
 * Called on mount to decide whether to show a button at all — offering one that
 * cannot work, or one that says "turn on" to somebody who already did, are both
 * worse than showing nothing.
 */
export async function pushState(appSlug: string): Promise<PushState> {
    if (!supported()) return 'unsupported';

    const key = await vapidKey(appSlug);
    if (key === null) return 'unconfigured';

    if (Notification.permission === 'denied') return 'denied';

    const registration = await navigator.serviceWorker.ready;
    const existing = await registration.pushManager.getSubscription();

    return existing === null ? 'off' : 'on';
}

async function vapidKey(appSlug: string): Promise<string | null> {
    const { data } = await axios
        .get<KeyResponse>(`/r/${appSlug}/push/key`)
        .catch(() => ({ data: { configured: false, key: null } }));

    return data.configured && data.key !== null && data.key !== ''
        ? data.key
        : null;
}

/**
 * Ask, subscribe, and register the result — or report why not.
 *
 * The server is told LAST, so a subscription only exists on our side once the
 * browser has actually granted one. The other order leaves rows addressing
 * devices that never agreed, and every notification to them is a request that
 * can only fail.
 */
export async function enablePush(appSlug: string): Promise<PushState> {
    if (!supported()) return 'unsupported';

    const key = await vapidKey(appSlug);
    if (key === null) return 'unconfigured';

    const permission = await Notification.requestPermission();
    if (permission !== 'granted') return 'denied';

    const registration = await navigator.serviceWorker.ready;

    const subscription =
        (await registration.pushManager.getSubscription()) ??
        (await registration.pushManager.subscribe({
            // Non-negotiable in every current browser: a subscription that
            // could deliver silently is not offered at all.
            userVisibleOnly: true,
            applicationServerKey: decodeKey(key),
        }));

    await axios.post(`/r/${appSlug}/push`, subscription.toJSON());

    return 'on';
}

/**
 * Stop.
 *
 * Both sides, in the order that cannot leave a device receiving notifications
 * it has asked to stop receiving: the browser's subscription goes first, so
 * even a failed request afterwards ends with nothing arriving.
 */
export async function disablePush(appSlug: string): Promise<PushState> {
    if (!supported()) return 'unsupported';

    const registration = await navigator.serviceWorker.ready;
    const subscription = await registration.pushManager.getSubscription();
    if (subscription === null) return 'off';

    const endpoint = subscription.endpoint;
    await subscription.unsubscribe();

    await axios
        .delete(`/r/${appSlug}/push`, { data: { endpoint } })
        .catch(() => undefined);

    return 'off';
}
