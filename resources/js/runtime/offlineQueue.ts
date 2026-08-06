import axios from 'axios';
import { computed, ref } from 'vue';

/**
 * Writes made without a signal, held until there is one.
 *
 * Phase 2 of offline. Phase 1 let a built app OPEN with no network; this lets
 * it be USED — a technician closes a work order in a basement and the close
 * lands when they walk out.
 *
 * THE THREE RULES, each of which is the reason a piece of this exists:
 *
 *  1. **A queued write is never reported as saved.** It is saved *here*, and
 *     the person is told so in those words. The alternative — a green toast
 *     for something that may still be refused — is the failure this whole
 *     feature would otherwise introduce, and it is worse than not shipping it.
 *
 *  2. **A replay must not write twice.** Every entry carries an idempotency
 *     key from the moment it is queued, so the retry after a half-delivered
 *     request is the SAME request, not a second one. Without this the feature
 *     invents duplicate orders, which is the one bug nobody forgives.
 *
 *  3. **A refused write is not silently dropped.** The server can reject what
 *     was queued (validation, a record deleted in the meantime, a permission
 *     revoked). That entry leaves the queue and lands somewhere the person can
 *     see it, because the only thing worse than a write that fails is a write
 *     that disappears.
 *
 * IndexedDB rather than localStorage: this must survive a reload and a crash,
 * and a queue that quietly hits a 5 MB string cap is a queue that loses work.
 * Written by hand for the same reason `public/sw.js` is — three object-store
 * calls do not earn a dependency.
 */

const DB_NAME = 'sapiensly-offline';
const DB_VERSION = 1;

/** Waiting to be sent. FIFO by `queuedAt`. */
const PENDING = 'pending';

/** Sent, and refused by the server. Waiting for a person, not for a signal. */
const REJECTED = 'rejected';

export interface QueuedWrite {
    id: string;
    /** Absolute runtime path, the same one the live request would use. */
    url: string;
    payload: unknown;
    /** Sent as `Idempotency-Key`; the server dedupes replays on it. */
    key: string;
    queuedAt: number;
    /** What the person was doing, for the "could not be saved" list. */
    label: string;
}

export interface RejectedWrite extends QueuedWrite {
    status?: number;
    reason: string;
}

/**
 * Live counts for the UI.
 *
 * Module-level rather than per-component: two mounted blocks watching the same
 * queue must agree, and — more importantly — only ONE of them may be flushing.
 */
const pendingCount = ref(0);
const rejected = ref<RejectedWrite[]>([]);
const flushing = ref(false);

export function useOfflineQueue() {
    return {
        pendingCount: computed(() => pendingCount.value),
        rejected: computed(() => rejected.value),
        flushing: computed(() => flushing.value),
        refresh,
        flush,
        discardRejected,
    };
}

export function offlineQueueSupported(): boolean {
    return typeof indexedDB !== 'undefined';
}

function open(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);

        request.onupgradeneeded = () => {
            const db = request.result;
            if (!db.objectStoreNames.contains(PENDING)) {
                db.createObjectStore(PENDING, { keyPath: 'id' });
            }
            if (!db.objectStoreNames.contains(REJECTED)) {
                db.createObjectStore(REJECTED, { keyPath: 'id' });
            }
        };

        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

function run<T>(store: string, mode: IDBTransactionMode, work: (s: IDBObjectStore) => IDBRequest<T>): Promise<T> {
    return open().then(
        (db) =>
            new Promise<T>((resolve, reject) => {
                const tx = db.transaction(store, mode);
                const request = work(tx.objectStore(store));
                request.onsuccess = () => resolve(request.result);
                request.onerror = () => reject(request.error);
                tx.oncomplete = () => db.close();
            }),
    );
}

/**
 * A key that is unique per queued write and stable across every retry of it.
 *
 * `crypto.randomUUID` where it exists; a time-plus-randomness fallback where it
 * does not (older Safari, and any non-secure context). The fallback is weaker
 * than a UUID and that is acceptable: the key only has to be unique within one
 * device's queue and one server-side dedupe window.
 */
function newKey(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
}

/**
 * Hold a write until there is a signal.
 *
 * Returns the entry so the caller can say what happened, or null when the
 * device cannot hold it at all — in which case the caller must report a plain
 * failure. Silently succeeding here would be rule 1 broken at the source.
 */
export async function enqueue(url: string, payload: unknown, label: string): Promise<QueuedWrite | null> {
    if (!offlineQueueSupported()) {
        return null;
    }

    const entry: QueuedWrite = {
        id: newKey(),
        url,
        payload,
        key: newKey(),
        queuedAt: Date.now(),
        label,
    };

    try {
        await run(PENDING, 'readwrite', (s) => s.add(entry));
        await refresh();

        return entry;
    } catch {
        return null;
    }
}

/** Re-read the counts the UI shows. */
export async function refresh(): Promise<void> {
    if (!offlineQueueSupported()) {
        return;
    }

    try {
        pendingCount.value = await run<number>(PENDING, 'readonly', (s) => s.count());
        rejected.value = await run<RejectedWrite[]>(REJECTED, 'readonly', (s) => s.getAll());
    } catch {
        pendingCount.value = 0;
        rejected.value = [];
    }
}

/**
 * Send what is waiting, oldest first.
 *
 * STRICTLY IN ORDER, and it stops at the first entry that could not reach a
 * server. Two reasons, and both are about correctness rather than tidiness:
 * a later write may update the record an earlier one created, and continuing
 * past a network failure just burns the rest of the queue against a network
 * that is still down.
 *
 * A write the server REFUSED is different: the network is fine and the entry
 * will be refused again forever, so it moves to `rejected` and the flush
 * carries on.
 */
export async function flush(): Promise<void> {
    if (flushing.value || !offlineQueueSupported()) {
        return;
    }

    flushing.value = true;

    try {
        const waiting = (await run<QueuedWrite[]>(PENDING, 'readonly', (s) => s.getAll())).sort(
            (a, b) => a.queuedAt - b.queuedAt,
        );

        for (const entry of waiting) {
            const outcome = await send(entry);

            if (outcome === 'unreachable') {
                break;
            }

            await run(PENDING, 'readwrite', (s) => s.delete(entry.id));
        }
    } catch {
        // A queue we cannot read is a queue we cannot flush. The entries are
        // still on disk; the next `online` event tries again.
    } finally {
        flushing.value = false;
        await refresh();
    }
}

export type Outcome = 'sent' | 'refused' | 'unreachable';

/**
 * What a failed attempt means for the entry that produced it.
 *
 * The whole queue turns on this one classification, so it is a function rather
 * than three `if`s inside the send loop.
 *
 * `unreachable` — the entry stays, untouched, and the flush stops. No status
 * at all means it never reached a server. A 5xx or a 429 DID reach one, but
 * they are "not now", not "no"; retrying them is safe precisely because the
 * entry carries an idempotency key.
 *
 * `refused` — the server considered it and said no (validation, a record
 * deleted in the meantime, a permission revoked). Retrying forever would be a
 * queue that never drains, so it leaves for the rejected list where a person
 * can see it.
 */
export function classify(status: number | undefined): Outcome {
    if (status === undefined || status >= 500 || status === 429) {
        return 'unreachable';
    }

    return 'refused';
}

async function send(entry: QueuedWrite): Promise<Outcome> {
    try {
        await axios.post(entry.url, entry.payload, {
            headers: { 'Idempotency-Key': entry.key },
            timeout: 30_000,
        });

        return 'sent';
    } catch (e) {
        const status = (e as { response?: { status?: number; data?: unknown } }).response?.status;

        if (classify(status) === 'unreachable') {
            return 'unreachable';
        }

        const body = (e as { response?: { data?: { message?: string } } }).response?.data;

        await run(REJECTED, 'readwrite', (s) =>
            s.put({
                ...entry,
                status,
                reason: typeof body?.message === 'string' && body.message !== '' ? body.message : `HTTP ${status}`,
            } satisfies RejectedWrite),
        );

        return 'refused';
    }
}

/** A person has read it and does not want it back. */
export async function discardRejected(id: string): Promise<void> {
    if (!offlineQueueSupported()) {
        return;
    }

    try {
        await run(REJECTED, 'readwrite', (s) => s.delete(id));
    } finally {
        await refresh();
    }
}

/**
 * Sign-out empties both stores, for the reason `sapiensly:purge` empties the
 * caches: what is in here is one person's unsent work, and the next person at
 * this device is not necessarily them.
 */
export async function purgeQueue(): Promise<void> {
    if (!offlineQueueSupported()) {
        return;
    }

    try {
        await run(PENDING, 'readwrite', (s) => s.clear());
        await run(REJECTED, 'readwrite', (s) => s.clear());
    } finally {
        await refresh();
    }
}

/**
 * Start watching for a signal.
 *
 * Called once from the runtime page. Flushes on `online` and on load, because
 * the tab may have been closed while offline and reopened with a connection —
 * in which case no `online` event ever fires.
 */
export function startOfflineQueue(): void {
    if (typeof window === 'undefined' || !offlineQueueSupported()) {
        return;
    }

    window.addEventListener('online', () => void flush());

    void refresh().then(() => {
        if (navigator.onLine) {
            void flush();
        }
    });
}
