/**
 * The one IndexedDB the runtime keeps, and the three stores in it.
 *
 * Shared by the write queue and the held files rather than one database each,
 * for the reason `purgeOfflineStorage` is one function: these are two halves of
 * the same unsent work — a queued form and the photo it refers to — and a
 * sign-out that cleared one and not the other would leave a device that looks
 * empty and is not.
 *
 * Written by hand, like `public/sw.js`, because this is four functions and a
 * spec-complete IndexedDB wrapper is not worth a dependency for them.
 */

const DB_NAME = 'sapiensly-offline';

/** 2 added `files`. Bumping this runs `onupgradeneeded` again. */
const DB_VERSION = 2;

/** Writes waiting to be sent. FIFO by `queuedAt`. */
export const PENDING = 'pending';

/** Sent, and refused by the server. Waiting for a person, not for a signal. */
export const REJECTED = 'rejected';

/** Bytes captured with no signal — a photo, a signature — and the write that needs them. */
export const FILES = 'files';

export function offlineDbSupported(): boolean {
    return typeof indexedDB !== 'undefined';
}

function open(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);

        request.onupgradeneeded = () => {
            const db = request.result;
            for (const store of [PENDING, REJECTED, FILES]) {
                if (!db.objectStoreNames.contains(store)) {
                    db.createObjectStore(store, { keyPath: 'id' });
                }
            }
        };

        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

/** One request against one store, as a promise. */
export function run<T>(
    store: string,
    mode: IDBTransactionMode,
    work: (s: IDBObjectStore) => IDBRequest<T>,
): Promise<T> {
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
 * A key that is unique per entry and stable across every retry of it.
 *
 * `crypto.randomUUID` where it exists; a time-plus-randomness fallback where it
 * does not (older Safari, and any non-secure context). The fallback is weaker
 * than a UUID and that is acceptable: the key only has to be unique within one
 * device's queue and one server-side dedupe window.
 */
export function newKey(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
}
