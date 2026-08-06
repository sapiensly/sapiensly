import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { installFakeIndexedDb } from './fakeIndexedDb';

vi.mock('axios', () => ({
    default: { post: vi.fn() },
}));

const fake = installFakeIndexedDb();

const { useOfflineQueue, refresh } = await import('./offlineQueue');
const { useRuntimeWrite } = await import('./useRuntimeWrite');

const post = vi.mocked(axios.post);

const { write } = useRuntimeWrite();

const refusedWith = (status: number) =>
    Object.assign(new Error('Request failed'), { response: { status, data: {} } });

const unreachable = () => new Error('Network Error');

beforeEach(async () => {
    fake.reset();
    post.mockReset();
    await refresh();
});

describe('the one place the runtime writes a record', () => {
    it('does not hold anything unless the caller asked it to', async () => {
        // Most of what goes through this seam must NOT wait: /extract is a
        // model call wanted now, /bulk acts on a selection that goes stale.
        post.mockRejectedValue(unreachable());

        const result = await write('/r/x/objects/o/extract', {});

        expect(result.ok).toBe(false);
        expect(result.queued).toBeUndefined();
        expect(useOfflineQueue().pendingCount.value).toBe(0);
    });

    it('holds a write that never reached a server, when asked', async () => {
        post.mockRejectedValue(unreachable());

        const result = await write('/r/x/actions', { n: 1 }, { queueOffline: true, label: 'close_order' });

        expect(result.queued).toBe(true);
        expect(useOfflineQueue().pendingCount.value).toBe(1);
    });

    it('never reports a held write as ok', async () => {
        // A caller that saw `ok` here would show a green toast for something
        // no server has agreed to. That is the one lie this must not tell.
        post.mockRejectedValue(unreachable());

        const result = await write('/r/x/actions', {}, { queueOffline: true, label: 'x' });

        expect(result.ok).toBe(false);
    });

    it('does not hold a write the server refused', async () => {
        // A 422 held for an hour is still a 422. Queueing it would turn a
        // fixable form error into work that silently never lands.
        post.mockRejectedValue(refusedWith(422));

        const result = await write('/r/x/actions', {}, { queueOffline: true, label: 'x' });

        expect(result.queued).toBeUndefined();
        expect(result.status).toBe(422);
        expect(useOfflineQueue().pendingCount.value).toBe(0);
    });

    it('holds nothing when the write succeeded', async () => {
        post.mockResolvedValue({ data: { ok: true } });

        const result = await write('/r/x/actions', {}, { queueOffline: true, label: 'x' });

        expect(result.ok).toBe(true);
        expect(useOfflineQueue().pendingCount.value).toBe(0);
    });
});
