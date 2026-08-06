/**
 * Registering the runtime's service worker, and forgetting what it kept.
 *
 * Kept out of `app.ts` so the SSR entry never imports it: `ssr.ts` runs in Node,
 * where `navigator` does not exist, and a top-level reference there is a build
 * that renders nothing.
 */

/** Mirrors the constant in public/sw.js. */
const PURGE = 'sapiensly:purge';

/**
 * Register the worker once the page has settled.
 *
 * Deliberately after `load`: registration competes with the first paint for
 * bandwidth, and the runtime's first paint is the thing a person is waiting
 * for. Offline is worth nothing on the visit where you install it.
 */
export function registerServiceWorker(): void {
    if (typeof window === 'undefined' || !('serviceWorker' in navigator)) {
        return;
    }

    // The dev server has no /build output and hot-reloads modules the worker
    // would happily serve stale. Nothing to gain, plenty to debug.
    if (import.meta.env.DEV) {
        return;
    }

    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {
            // A refused registration (private mode, an unsupported browser, a
            // policy) costs the user nothing here — the app works online. It is
            // not worth a console error on every load.
        });
    });
}

/**
 * Throw away everything the worker kept.
 *
 * Called on sign-out. What is cached is data THIS person was allowed to see,
 * and the next person to pick up the device is not necessarily them. Storage
 * that outlives the session is the whole risk offline introduces, so it is
 * closed at the one moment we know the session ended.
 */
export async function purgeOfflineCaches(): Promise<void> {
    if (typeof window === 'undefined' || !('serviceWorker' in navigator)) {
        return;
    }

    navigator.serviceWorker.controller?.postMessage({ type: PURGE });

    // Belt and braces: the page can reach the same Cache Storage the worker
    // uses, and a sign-out with no controller (first load, worker still
    // installing) must still clear.
    if ('caches' in window) {
        const names = await caches.keys();
        await Promise.all(
            names.filter((name) => name.startsWith('sapiensly-')).map((name) => caches.delete(name)),
        );
    }
}
