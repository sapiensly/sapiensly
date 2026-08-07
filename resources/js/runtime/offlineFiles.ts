import { FILES, newKey, offlineDbSupported, run } from './offlineDb';

/**
 * A photo taken where there is no signal.
 *
 * Phase 3 of offline, and the half without which Phase 2 is a promise the app
 * cannot keep: a work order closed on site is closed with the PHOTO of the
 * meter and the customer's SIGNATURE on it, and both are uploads. Queue the
 * form without them and the technician drives back.
 *
 * THE SHAPE OF THE TRICK. An upload that cannot reach a server returns a
 * file id that LOOKS like a real one to everything downstream — the form field,
 * the preview, the record payload — but starts with `filq_` and refers to a
 * blob held here. When the queued write is finally sent, the queue resolves
 * every held file first: uploads the bytes, gets the real id, and substitutes
 * it into the payload before the write goes anywhere. The server never learns
 * that any of this happened.
 *
 * The alternative — inlining bytes into the action payload as base64 — would
 * put a 4 MB photo through the JSON write endpoint, change a contract three
 * other callers depend on, and grow the row by a third. This keeps uploads
 * uploads.
 */

/** Marks an id as ours. Chosen to survive a `startsWith('fil')` check upstream. */
const PENDING_PREFIX = 'filq_';

/**
 * What one device may hold in unsent bytes.
 *
 * A cap, not an optimisation. IndexedDB quota is per-origin and browser-decided,
 * and hitting it produces an opaque failure halfway through a write. Refusing at
 * a number we chose lets us say WHY, which is the difference between "there is
 * no room for more photos" and a form that silently will not accept one.
 */
const BUDGET_BYTES = 100 * 1024 * 1024;

/** The shape `useFileUpload` returns, so a held file is a drop-in for a real one. */
export interface HeldFile {
    file_id: string;
    original_name: string;
    mime: string;
    size_bytes: number;
    /** An object URL, so the preview works before anything is uploaded. */
    url: string;
    /** Absent on a real upload. The UI keys off it to say "not sent yet". */
    pending: true;
}

interface StoredFile {
    id: string;
    blob: Blob;
    original_name: string;
    mime: string;
    size_bytes: number;
}

export function isHeldFileId(value: unknown): boolean {
    return typeof value === 'string' && value.startsWith(PENDING_PREFIX);
}

/**
 * Keep the bytes, hand back something the form can use.
 *
 * Returns null when we cannot hold them — no IndexedDB, or the budget is
 * spent. The caller must then report a plain upload failure: a form that
 * accepts a photo it did not keep is worse than one that says it could not.
 */
export async function holdFile(blob: Blob, filename: string): Promise<HeldFile | null> {
    if (!offlineDbSupported()) {
        return null;
    }

    try {
        if ((await heldBytes()) + blob.size > BUDGET_BYTES) {
            return null;
        }

        const stored: StoredFile = {
            id: PENDING_PREFIX + newKey(),
            blob,
            original_name: filename,
            mime: blob.type || 'application/octet-stream',
            size_bytes: blob.size,
        };

        await run(FILES, 'readwrite', (s) => s.add(stored));

        return {
            file_id: stored.id,
            original_name: stored.original_name,
            mime: stored.mime,
            size_bytes: stored.size_bytes,
            url: URL.createObjectURL(blob),
            pending: true,
        };
    } catch {
        return null;
    }
}

export async function heldBytes(): Promise<number> {
    if (!offlineDbSupported()) {
        return 0;
    }

    try {
        const all = await run<StoredFile[]>(FILES, 'readonly', (s) => s.getAll());

        return all.reduce((sum, f) => sum + (f.size_bytes ?? 0), 0);
    } catch {
        return 0;
    }
}

export type ResolveOutcome = 'ok' | 'refused' | 'unreachable';

/** What an upload attempt produced, in the queue's own vocabulary. */
export interface UploadAttempt {
    outcome: ResolveOutcome;
    /** The server's answer, on success. Substituted into the payload. */
    file?: Record<string, unknown>;
    reason?: string;
}

/**
 * Swap every held file in a payload for the real thing.
 *
 * Walks generically and substitutes the WHOLE object rather than the id alone:
 * a file value in a record is `{file_id, url, mime, size_bytes, …}`, and
 * replacing only the id would leave a `blob:` url pointing at a page that
 * closed hours ago, stored in the record forever.
 *
 * Mutates nothing — the caller keeps the original in case the flush stops, so
 * a half-resolved payload never reaches disk.
 */
export async function resolveHeldFiles(
    payload: unknown,
    upload: (blob: Blob, filename: string, key: string) => Promise<UploadAttempt>,
): Promise<{ outcome: ResolveOutcome; payload: unknown; reason?: string }> {
    const held = collectHeldIds(payload);

    if (held.length === 0) {
        return { outcome: 'ok', payload };
    }

    const resolved = new Map<string, Record<string, unknown>>();

    for (const id of held) {
        const stored = await run<StoredFile | undefined>(FILES, 'readonly', (s) => s.get(id));

        if (stored === undefined) {
            // The bytes are gone — a purge, a browser evicting storage. The
            // write cannot be completed as written and saying so beats sending
            // a record that points at nothing.
            return { outcome: 'refused', payload, reason: `Missing attachment ${id}` };
        }

        // The key is the held id itself: stable across retries by construction,
        // so a half-delivered upload is deduped rather than orphaning bytes.
        const attempt = await upload(stored.blob, stored.original_name, id);

        if (attempt.outcome !== 'ok' || attempt.file === undefined) {
            return { outcome: attempt.outcome, payload, reason: attempt.reason };
        }

        resolved.set(id, attempt.file);
    }

    return { outcome: 'ok', payload: substitute(payload, resolved) };
}

/** Forget the bytes for files that made it, so the store does not grow forever. */
export async function releaseFiles(ids: string[]): Promise<void> {
    if (!offlineDbSupported()) {
        return;
    }

    for (const id of ids) {
        try {
            await run(FILES, 'readwrite', (s) => s.delete(id));
        } catch {
            // A blob we cannot delete costs space, not correctness.
        }
    }
}

export function collectHeldIds(node: unknown): string[] {
    const found = new Set<string>();

    const walk = (value: unknown): void => {
        if (Array.isArray(value)) {
            value.forEach(walk);

            return;
        }

        if (value !== null && typeof value === 'object') {
            const record = value as Record<string, unknown>;
            if (isHeldFileId(record.file_id)) {
                found.add(record.file_id as string);
            }
            Object.values(record).forEach(walk);
        }
    };

    walk(node);

    return [...found];
}

function substitute(node: unknown, resolved: Map<string, Record<string, unknown>>): unknown {
    if (Array.isArray(node)) {
        return node.map((item) => substitute(item, resolved));
    }

    if (node !== null && typeof node === 'object') {
        const record = node as Record<string, unknown>;

        if (typeof record.file_id === 'string' && resolved.has(record.file_id)) {
            return resolved.get(record.file_id);
        }

        return Object.fromEntries(Object.entries(record).map(([k, v]) => [k, substitute(v, resolved)]));
    }

    return node;
}

/**
 * Sign-out forgets the bytes too.
 *
 * A photo of somebody's meter, or their signature, is exactly the kind of thing
 * that must not survive on a device the next person picks up.
 */
export async function purgeFiles(): Promise<void> {
    if (!offlineDbSupported()) {
        return;
    }

    try {
        await run(FILES, 'readwrite', (s) => s.clear());
    } catch {
        // Nothing better to do; the caller is on its way out.
    }
}
