import { onBeforeUnmount, onMounted, watch, type Ref } from 'vue';

/**
 * Keeping the screen on while a page is open.
 *
 * The reason this is not two lines inline: a wake lock is RELEASED by the
 * browser every time the document is hidden, and it is not restored when the
 * document comes back. So the app that took a lock, got a phone call, and came
 * back has no lock and no idea — the screen dims mid-inspection and the person
 * blames the app, correctly. Re-acquiring on visibility is the entire point of
 * the thing, and it is the part that gets left out.
 *
 * Nothing here fails loudly. A browser without the API (Safari before 16.4,
 * every Firefox until recently) simply behaves as it always has: the screen
 * times out. That is a worse experience, not a broken one.
 */
interface WakeLockSentinelLike {
    released?: boolean;
    release: () => Promise<void>;
}

interface WakeLockLike {
    request: (type: 'screen') => Promise<WakeLockSentinelLike>;
}

/**
 * The lock, held across the hiding and showing of a document.
 *
 * Split from the composable so a test can drive it: `document.hidden` is not
 * settable in jsdom and the failure this exists to prevent only happens on the
 * second visibility change.
 */
export class WakeLockKeeper {
    private sentinel: WakeLockSentinelLike | null = null;

    /** Whether the caller currently WANTS the screen kept on. */
    private wanted = false;

    constructor(
        private readonly wakeLock: WakeLockLike | undefined,
        private readonly isVisible: () => boolean,
    ) {}

    async want(on: boolean): Promise<void> {
        this.wanted = on;

        if (on) {
            await this.acquire();

            return;
        }

        await this.release();
    }

    /**
     * The document became visible again, or went away.
     *
     * Only ever re-acquires something the caller still wants: a page that
     * turned the lock off while hidden must not have it handed back on return.
     */
    async onVisibilityChange(): Promise<void> {
        if (this.wanted && this.isVisible()) {
            await this.acquire();
        }
    }

    /** Whether a live lock is held right now. For tests and for a status line. */
    get held(): boolean {
        return this.sentinel !== null && this.sentinel.released !== true;
    }

    private async acquire(): Promise<void> {
        if (this.wakeLock === undefined || this.held || !this.isVisible()) {
            return;
        }

        try {
            this.sentinel = await this.wakeLock.request('screen');
        } catch {
            // Refused (a background tab, a battery-saver policy). The screen
            // will dim, which is what it did before anyone asked.
            this.sentinel = null;
        }
    }

    private async release(): Promise<void> {
        const sentinel = this.sentinel;
        this.sentinel = null;
        if (sentinel === null) return;

        try {
            await sentinel.release();
        } catch {
            // Already gone. Nothing to undo.
        }
    }
}

/**
 * Keep the screen on for as long as `active` says so.
 *
 * For a page somebody works on with their hands rather than their eyes — a long
 * inspection form, a scanning station, the till on the counter. Authored per
 * page, because "this screen must not sleep" is a fact about ONE screen and an
 * app-wide version would hold the phone awake on the list somebody left open.
 */
export function useWakeLock(active: Ref<boolean>): void {
    const keeper = new WakeLockKeeper(
        typeof navigator !== 'undefined'
            ? (navigator as unknown as { wakeLock?: WakeLockLike }).wakeLock
            : undefined,
        () =>
            typeof document === 'undefined' ||
            document.visibilityState !== 'hidden',
    );

    const onVisibility = () => void keeper.onVisibilityChange();

    onMounted(() => {
        document.addEventListener('visibilitychange', onVisibility);
        void keeper.want(active.value);
    });

    watch(active, (on) => void keeper.want(on));

    onBeforeUnmount(() => {
        document.removeEventListener('visibilitychange', onVisibility);
        void keeper.want(false);
    });
}
