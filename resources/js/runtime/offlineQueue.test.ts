import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { installFakeIndexedDb } from './fakeIndexedDb';

vi.mock('axios', () => ({
    default: { post: vi.fn() },
}));

const fake = installFakeIndexedDb();

const { classify, discardRejected, enqueue, flush, refresh, purgeQueue, useOfflineQueue } = await import('./offlineQueue');
const { holdFile } = await import('./offlineFiles');

const post = vi.mocked(axios.post);

/** An axios rejection, shaped the way the interceptor-free client throws. */
const refusedWith = (status: number, message?: string) =>
    Object.assign(new Error('Request failed'), {
        response: { status, data: message ? { message } : {} },
    });

/** No `response` at all — the request never reached a server. */
const unreachable = () => new Error('Network Error');

beforeEach(async () => {
    fake.reset();
    post.mockReset();
    globalThis.URL.createObjectURL = vi.fn(() => 'blob:fake');
    await refresh();
});

describe('classifying a failed attempt', () => {
    it('treats anything that never reached a server as retryable', () => {
        expect(classify(undefined)).toBe('unreachable');
    });

    it('treats a server saying "not now" as retryable', () => {
        // 5xx and 429 reached a server, but retrying them is safe precisely
        // because the entry carries an idempotency key.
        expect(classify(500)).toBe('unreachable');
        expect(classify(503)).toBe('unreachable');
        expect(classify(429)).toBe('unreachable');
    });

    it('treats a considered refusal as final', () => {
        // Validation, a deleted record, a revoked permission. Retrying forever
        // would be a queue that never drains.
        for (const status of [400, 403, 404, 409, 422]) {
            expect(classify(status)).toBe('refused');
        }
    });

    it('comes back for our own in-flight 409', () => {
        // The dedupe middleware answers 409 while an identical attempt is
        // still running. Read as an ordinary conflict it would RETIRE a write
        // that in fact just needs asking again in a moment — so the middleware
        // labels it, and only the labelled one is retryable.
        expect(classify(409, { 'idempotent-retry': 'true' })).toBe('unreachable');
        expect(classify(409, {})).toBe('refused');
    });
});

describe('holding a write', () => {
    it('gives every entry its own idempotency key', async () => {
        const a = await enqueue('/r/x/actions', { actions: [] }, 'create_record');
        const b = await enqueue('/r/x/actions', { actions: [] }, 'create_record');

        expect(a?.key).toBeTruthy();
        expect(b?.key).toBeTruthy();
        expect(a?.key).not.toBe(b?.key);
    });

    it('counts what is waiting', async () => {
        await enqueue('/r/x/actions', {}, 'a');
        await enqueue('/r/x/actions', {}, 'b');

        expect(useOfflineQueue().pendingCount.value).toBe(2);
    });
});

describe('sending what was held', () => {
    it('sends oldest first, with the key it was queued under', async () => {
        const first = await enqueue('/r/x/actions', { n: 1 }, 'first');
        const second = await enqueue('/r/x/actions', { n: 2 }, 'second');
        // Queued in one tick, so make the order unambiguous rather than
        // relying on Date.now() having advanced.
        second!.queuedAt = first!.queuedAt + 1000;
        await purgeAndReseed([first!, second!]);

        post.mockResolvedValue({ data: {} });

        await flush();

        expect(post.mock.calls.map((c) => c[1])).toEqual([{ n: 1 }, { n: 2 }]);
        expect(post.mock.calls[0][2]?.headers?.['Idempotency-Key']).toBe(first!.key);
        expect(useOfflineQueue().pendingCount.value).toBe(0);
    });

    it('stops at the first entry that cannot reach a server', async () => {
        // A later write may update the record an earlier one created, so the
        // order is not an optimisation — and burning the rest of the queue
        // against a network that is still down helps nobody.
        const first = await enqueue('/r/x/actions', { n: 1 }, 'first');
        const second = await enqueue('/r/x/actions', { n: 2 }, 'second');
        second!.queuedAt = first!.queuedAt + 1000;
        await purgeAndReseed([first!, second!]);

        post.mockRejectedValue(unreachable());

        await flush();

        expect(post).toHaveBeenCalledTimes(1);
        expect(useOfflineQueue().pendingCount.value).toBe(2);
    });

    it('keeps going past a write the server refused, and remembers it', async () => {
        const first = await enqueue('/r/x/actions', { n: 1 }, 'close_order');
        const second = await enqueue('/r/x/actions', { n: 2 }, 'add_part');
        second!.queuedAt = first!.queuedAt + 1000;
        await purgeAndReseed([first!, second!]);

        post.mockRejectedValueOnce(refusedWith(422, 'Folio is required.'));
        post.mockResolvedValueOnce({ data: {} });

        await flush();

        const { pendingCount, rejected } = useOfflineQueue();

        expect(post).toHaveBeenCalledTimes(2);
        expect(pendingCount.value).toBe(0);
        expect(rejected.value).toHaveLength(1);
        expect(rejected.value[0].label).toBe('close_order');
        expect(rejected.value[0].reason).toBe('Folio is required.');
    });

    it('never silently drops a refused write', async () => {
        // The only thing worse than a write that fails is a write that
        // disappears: it left the pending store, so it must be somewhere a
        // person can find it.
        await enqueue('/r/x/actions', { n: 1 }, 'close_order');
        post.mockRejectedValue(refusedWith(403));

        await flush();

        expect(useOfflineQueue().pendingCount.value).toBe(0);
        expect(useOfflineQueue().rejected.value).toHaveLength(1);
    });

    it('leaves a rate-limited write in the queue', async () => {
        await enqueue('/r/x/actions', { n: 1 }, 'close_order');
        post.mockRejectedValue(refusedWith(429));

        await flush();

        expect(useOfflineQueue().pendingCount.value).toBe(1);
        expect(useOfflineQueue().rejected.value).toHaveLength(0);
    });

    it('does not run two flushes at once', async () => {
        await enqueue('/r/x/actions', { n: 1 }, 'a');
        post.mockResolvedValue({ data: {} });

        await Promise.all([flush(), flush()]);

        expect(post).toHaveBeenCalledTimes(1);
    });
});

describe('a write that carries a photo taken offline', () => {
    it('uploads the bytes first, then sends the write with the real id', async () => {
        const held = await holdFile(new Blob([new Uint8Array(4)], { type: 'image/png' }), 'medidor.png');
        await enqueue('/r/campo/actions', { values: { foto: held } }, 'close_order');

        post.mockImplementation(async (url: string) =>
            url.endsWith('/uploads') ? { data: { file_id: 'fil_real', url: '/r/campo/files/fil_real' } } : { data: {} },
        );

        await flush();

        const [uploadCall, writeCall] = post.mock.calls;
        expect(uploadCall[0]).toBe('/r/campo/uploads');
        // The bytes go up on the write's own mount — a portal form posting to
        // /r/ would 401 on every attachment.
        expect(writeCall[0]).toBe('/r/campo/actions');
        expect(JSON.stringify(writeCall[1])).toContain('fil_real');
        expect(useOfflineQueue().pendingCount.value).toBe(0);
    });

    it('does not send the write when the photo cannot be uploaded yet', async () => {
        // A record pointing at a photo that failed to upload is worse than one
        // not written: it is written, it looks complete, and the photo is gone.
        const held = await holdFile(new Blob([new Uint8Array(4)], { type: 'image/png' }), 'medidor.png');
        await enqueue('/r/campo/actions', { values: { foto: held } }, 'close_order');

        post.mockRejectedValue(unreachable());

        await flush();

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe('/r/campo/uploads');
        expect(useOfflineQueue().pendingCount.value).toBe(1);
    });

    it('rejects the write when the server refuses the photo', async () => {
        const held = await holdFile(new Blob([new Uint8Array(4)], { type: 'image/png' }), 'medidor.png');
        await enqueue('/r/campo/actions', { values: { foto: held } }, 'close_order');

        post.mockRejectedValue(refusedWith(413, 'File too large.'));

        await flush();

        const { pendingCount, rejected } = useOfflineQueue();
        expect(pendingCount.value).toBe(0);
        expect(rejected.value[0].reason).toBe('File too large.');
    });

    it('sends the idempotency key the file was held under', async () => {
        // Stable across retries by construction, so a half-delivered upload is
        // deduped rather than orphaning bytes in tenant storage.
        const held = await holdFile(new Blob([new Uint8Array(4)], { type: 'image/png' }), 'medidor.png');
        await enqueue('/r/campo/actions', { values: { foto: held } }, 'close_order');

        post.mockResolvedValue({ data: {} });

        await flush();

        expect(post.mock.calls[0][2]?.headers?.['Idempotency-Key']).toBe(held!.file_id);
    });
});

describe('what a person can clear', () => {
    it('discards a rejected entry they have read', async () => {
        await enqueue('/r/x/actions', { n: 1 }, 'close_order');
        post.mockRejectedValue(refusedWith(422));
        await flush();

        await discardRejected(useOfflineQueue().rejected.value[0].id);

        expect(useOfflineQueue().rejected.value).toHaveLength(0);
    });

    it('forgets everything on sign-out', async () => {
        // Unsent work belongs to the person who was signed in, and the next
        // person at this device is not necessarily them.
        await enqueue('/r/x/actions', { n: 1 }, 'pending');
        await enqueue('/r/x/actions', { n: 2 }, 'doomed');
        post.mockRejectedValueOnce(refusedWith(422));
        post.mockResolvedValueOnce({ data: {} });
        await flush();

        await purgeQueue();

        expect(useOfflineQueue().pendingCount.value).toBe(0);
        expect(useOfflineQueue().rejected.value).toHaveLength(0);
    });
});

/** Re-seed the pending store so `queuedAt` edits take effect on disk. */
async function purgeAndReseed(entries: Array<{ id: string }>): Promise<void> {
    await purgeQueue();
    const store = fake.database.stores.get('pending')!;
    entries.forEach((e) => store.set(e.id, e as never));
    await refresh();
}
