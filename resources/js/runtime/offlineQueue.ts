import axios from 'axios';
import { computed, ref } from 'vue';
import { newKey, offlineDbSupported, PENDING, REJECTED, run } from './offlineDb';
import { collectHeldIds, purgeFiles, releaseFiles, resolveHeldFiles, type UploadAttempt } from './offlineFiles';

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
 * Attachments captured offline live beside the queue and are resolved into the
 * payload just before it is sent — see `offlineFiles.ts`.
 */

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
    return offlineDbSupported();
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
 *
 * `Idempotent-Retry` is the one case a 4xx is retryable, and it is OUR 409:
 * the dedupe middleware saying an identical attempt is still in flight. Read
 * from a header rather than by widening the status rule, because a real 409
 * from the app IS a considered refusal and must stay one.
 */
export function classify(status: number | undefined, headers: Record<string, unknown> = {}): Outcome {
    if (status === undefined || status >= 500 || status === 429) {
        return 'unreachable';
    }

    if (status === 409 && String(headers['idempotent-retry'] ?? '') === 'true') {
        return 'unreachable';
    }

    return 'refused';
}

async function send(entry: QueuedWrite): Promise<Outcome> {
    // Attachments first, and the write only if they ALL land. A record that
    // points at a photo which failed to upload is worse than one not written
    // yet: it is written, it looks complete, and the photo is gone.
    const held = collectHeldIds(entry.payload);
    const attachments = await resolveHeldFiles(entry.payload, (blob, filename, key) =>
        uploadHeld(entry.url, blob, filename, key),
    );

    if (attachments.outcome === 'unreachable') {
        return 'unreachable';
    }

    if (attachments.outcome === 'refused') {
        await reject(entry, undefined, attachments.reason ?? 'An attachment could not be uploaded.');

        return 'refused';
    }

    try {
        await axios.post(entry.url, attachments.payload, {
            headers: { 'Idempotency-Key': entry.key },
            timeout: 30_000,
        });

        // Only once the write itself landed. Released earlier, a retry of the
        // write would find its own attachments gone.
        await releaseFiles(held);

        return 'sent';
    } catch (e) {
        const response = (e as { response?: { status?: number; headers?: Record<string, unknown> } }).response;
        const status = response?.status;

        if (classify(status, response?.headers) === 'unreachable') {
            return 'unreachable';
        }

        const body = (e as { response?: { data?: { message?: string } } }).response?.data;

        await reject(
            entry,
            status,
            typeof body?.message === 'string' && body.message !== '' ? body.message : `HTTP ${status}`,
        );

        return 'refused';
    }
}

async function reject(entry: QueuedWrite, status: number | undefined, reason: string): Promise<void> {
    await run(REJECTED, 'readwrite', (s) => s.put({ ...entry, status, reason } satisfies RejectedWrite));

    // The bytes go with it. Kept, they would sit in the budget forever backing
    // a write that is never going to be sent.
    await releaseFiles(collectHeldIds(entry.payload));
}

/**
 * Send one held attachment, on the mount its write belongs to.
 *
 * The idempotency key is the held file's own id: stable across every retry by
 * construction, so a half-delivered upload is deduped server-side rather than
 * leaving an orphan blob in tenant storage behind a record nobody points at.
 */
async function uploadHeld(writeUrl: string, blob: Blob, filename: string, key: string): Promise<UploadAttempt> {
    const form = new FormData();
    form.append('file', blob, filename);

    try {
        const { data } = await axios.post(writeUrl.replace(/\/actions$/, '/uploads'), form, {
            headers: { 'Content-Type': 'multipart/form-data', 'Idempotency-Key': key },
            timeout: 120_000,
        });

        return { outcome: 'ok', file: data as Record<string, unknown> };
    } catch (e) {
        const response = (e as {
            response?: { status?: number; headers?: Record<string, unknown>; data?: { message?: string } };
        }).response;

        if (classify(response?.status, response?.headers) === 'unreachable') {
            return { outcome: 'unreachable' };
        }

        return {
            outcome: 'refused',
            reason: response?.data?.message ?? `Upload refused (HTTP ${response?.status}).`,
        };
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
        // A photo of somebody's meter and their signature on it are exactly
        // what must not survive on a device the next person picks up.
        await purgeFiles();
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
