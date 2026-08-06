import { onMounted, onUnmounted, ref } from 'vue';

/**
 * Whether there is a signal, and how old what you are looking at is.
 *
 * The second half is the point. An app that silently shows yesterday's work
 * orders is worse than one that will not open: the technician acts on them. So
 * the runtime never renders cached rows without saying, in the same breath,
 * when they were fetched — the same rule EnvironmentBar follows for sandbox
 * data, where every number on the page is fiction and says so.
 */

/** Mirrors the constant in public/sw.js. */
const CACHED_AT = 'x-sapiensly-cached-at';

const DATA_CACHE = 'sapiensly-data-v1';

export function useOfflineStatus() {
    const online = ref(typeof navigator === 'undefined' ? true : navigator.onLine);

    /** When the data on screen was fetched. Null while it is live. */
    const cachedAt = ref<Date | null>(null);

    function goOnline() {
        online.value = true;
        cachedAt.value = null;
    }

    function goOffline() {
        online.value = false;
        void readCachedAt();
    }

    /**
     * Read the stamp the worker wrote, from the page.
     *
     * Cache Storage is reachable from both sides, so this needs no message
     * round-trip with the worker — and it still answers when the worker is
     * installing and has not claimed the page yet.
     */
    async function readCachedAt(): Promise<void> {
        if (typeof window === 'undefined' || !('caches' in window)) {
            return;
        }

        try {
            const cache = await caches.open(DATA_CACHE);
            const keys = await cache.keys();

            // The page's own url, whichever partial it was stored under. The
            // freshest stamp among them is the age of what is on screen.
            const here = keys.filter((request) => request.url.startsWith(window.location.href.split('#')[0]));

            let newest: Date | null = null;
            for (const request of here) {
                const hit = await cache.match(request);
                const stamp = hit?.headers.get(CACHED_AT);
                if (!stamp) {
                    continue;
                }
                const at = new Date(stamp);
                if (!newest || at > newest) {
                    newest = at;
                }
            }

            cachedAt.value = newest;
        } catch {
            // A browser that refuses Cache Storage (private mode) still gets
            // the offline banner, just without an age on it.
            cachedAt.value = null;
        }
    }

    onMounted(() => {
        window.addEventListener('online', goOnline);
        window.addEventListener('offline', goOffline);

        if (!online.value) {
            void readCachedAt();
        }
    });

    onUnmounted(() => {
        window.removeEventListener('online', goOnline);
        window.removeEventListener('offline', goOffline);
    });

    return { online, cachedAt };
}

/**
 * "hace 2 h" — how old, in the coarsest unit that is still true.
 *
 * Coarse on purpose: "hace 2 h" is actionable and "hace 127 minutos" is not,
 * and a precise number invites a trust the number does not deserve.
 */
export function describeAge(at: Date | null, locale: string): string | null {
    if (!at) {
        return null;
    }

    const minutes = Math.max(0, Math.round((Date.now() - at.getTime()) / 60_000));
    const es = locale.startsWith('es');

    if (minutes < 1) {
        return es ? 'hace un momento' : 'just now';
    }
    if (minutes < 60) {
        return es ? `hace ${minutes} min` : `${minutes} min ago`;
    }

    const hours = Math.round(minutes / 60);
    if (hours < 24) {
        return es ? `hace ${hours} h` : `${hours} h ago`;
    }

    const days = Math.round(hours / 24);

    return es ? `hace ${days} d` : `${days} d ago`;
}
