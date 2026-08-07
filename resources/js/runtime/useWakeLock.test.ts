import { describe, expect, it, vi } from 'vitest';
import { WakeLockKeeper } from './useWakeLock';

/**
 * The lock, across the hiding and showing of a document.
 *
 * The case worth a test is the SECOND visibility change: a wake lock is
 * released by the browser whenever the document hides, and it is never handed
 * back. An app that took one, got a phone call and came back has no lock and no
 * idea — the screen dims mid-inspection and the person blames the app.
 */
function fakeWakeLock() {
    const sentinels: Array<{
        released: boolean;
        release: () => Promise<void>;
    }> = [];

    return {
        sentinels,
        request: vi.fn(async () => {
            const sentinel = {
                released: false,
                release: async () => {
                    sentinel.released = true;
                },
            };
            sentinels.push(sentinel);

            return sentinel;
        }),
    };
}

describe('holding the screen awake', () => {
    it('takes a lock when asked and gives it back when told to stop', async () => {
        const wakeLock = fakeWakeLock();
        const keeper = new WakeLockKeeper(wakeLock, () => true);

        await keeper.want(true);
        expect(keeper.held).toBe(true);

        await keeper.want(false);
        expect(keeper.held).toBe(false);
        expect(wakeLock.sentinels[0].released).toBe(true);
    });

    it('takes only one lock however often it is asked', async () => {
        const wakeLock = fakeWakeLock();
        const keeper = new WakeLockKeeper(wakeLock, () => true);

        await keeper.want(true);
        await keeper.want(true);
        await keeper.onVisibilityChange();

        expect(wakeLock.request).toHaveBeenCalledTimes(1);
    });

    it('re-acquires when the document comes back', async () => {
        const wakeLock = fakeWakeLock();
        let visible = true;
        const keeper = new WakeLockKeeper(wakeLock, () => visible);

        await keeper.want(true);

        // The browser drops it on the way out and tells nobody.
        wakeLock.sentinels[0].released = true;
        visible = false;
        await keeper.onVisibilityChange();
        expect(wakeLock.request).toHaveBeenCalledTimes(1);

        visible = true;
        await keeper.onVisibilityChange();
        expect(wakeLock.request).toHaveBeenCalledTimes(2);
        expect(keeper.held).toBe(true);
    });

    it('does not hand back a lock the page turned off while hidden', async () => {
        const wakeLock = fakeWakeLock();
        let visible = true;
        const keeper = new WakeLockKeeper(wakeLock, () => visible);

        await keeper.want(true);
        visible = false;
        await keeper.want(false);

        visible = true;
        await keeper.onVisibilityChange();

        expect(wakeLock.request).toHaveBeenCalledTimes(1);
        expect(keeper.held).toBe(false);
    });

    it('is a no-op where the browser has no wake lock', async () => {
        const keeper = new WakeLockKeeper(undefined, () => true);

        await expect(keeper.want(true)).resolves.toBeUndefined();
        expect(keeper.held).toBe(false);
    });

    it('survives a refusal', async () => {
        // A background tab, a battery-saver policy. The screen dims, which is
        // what it did before anyone asked.
        const wakeLock = {
            request: vi.fn().mockRejectedValue(new Error('NotAllowedError')),
        };
        const keeper = new WakeLockKeeper(wakeLock, () => true);

        await keeper.want(true);

        expect(keeper.held).toBe(false);
    });
});
