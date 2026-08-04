import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';

/**
 * Keeping a page honest while other people work.
 *
 * ONE subscription per page, not one per block. A dashboard with six live
 * blocks would otherwise open six websocket channels to the same app and
 * refresh six times for one write — the blocks all reload together anyway,
 * because `blockData` arrives as a single deferred prop.
 *
 * What arrives is three ids and a verb, never the row: see the server event for
 * why. So a change is a REASON TO RE-READ rather than data to apply, and the
 * re-read goes through the ordinary access-filtered path — which is the only
 * place that knows what this particular reader may see.
 */
export interface LiveOptions {
    appId: string;
    /** Only refresh for these objects. Empty means every object in the app. */
    objectIds: string[];
    /** The environment this page is showing. A demo write must not blink production. */
    environment: string;
    /** This browser's own user, so it can ignore the echo of its own write. */
    currentUserId: number | null;
}

interface RecordChanged {
    appId: string;
    objectId: string;
    recordId: string;
    verb: string;
    environment: string;
    actorId: number | null;
}

/** Somebody else is looking at this too. */
export interface Watcher {
    id: number;
    name: string;
}

export function useLiveRecords(options: LiveOptions) {
    const watchers = ref<Watcher[]>([]);

    const echo = (window as unknown as { Echo?: unknown }).Echo as
        | {
              private: (name: string) => {
                  listen: (
                      event: string,
                      cb: (e: RecordChanged) => void,
                  ) => unknown;
              };
              leave: (name: string) => void;
          }
        | undefined;

    if (echo === undefined) {
        // No websocket in this deployment. The page still works; it just does
        // not move on its own, which is what it did before any of this existed.
        return { watchers };
    }

    const channelName = `app.records.${options.appId}`;
    let pending: ReturnType<typeof setTimeout> | undefined;

    /**
     * Ten writes in a second — somebody pasting a batch, a workflow fanning out
     * — are one reason to re-read, not ten. Coalesced so a busy app costs one
     * query rather than a queue of them.
     */
    function scheduleReload(): void {
        clearTimeout(pending);
        pending = setTimeout(() => {
            router.reload({ only: ['blockData'] });
        }, 400);
    }

    echo.private(channelName).listen(
        '.RecordChanged',
        (event: RecordChanged) => {
            // Its own echo. The write already applied here, and reloading under
            // somebody who is mid-edit takes the cursor with it.
            if (
                options.currentUserId !== null &&
                event.actorId === options.currentUserId
            ) {
                return;
            }

            if (event.environment !== options.environment) return;

            if (
                options.objectIds.length > 0 &&
                !options.objectIds.includes(event.objectId)
            ) {
                return;
            }

            scheduleReload();
        },
    );

    onBeforeUnmount(() => {
        clearTimeout(pending);
        echo.leave(channelName);
    });

    return { watchers };
}

/**
 * Who else has this record open.
 *
 * A presence channel and nothing more: it says somebody is HERE, never what
 * they are doing. Two people about to edit the same order is the thing worth
 * knowing, and knowing it is what stops the second one wasting the work.
 */
export function useRecordPresence(
    appId: string,
    recordId: string,
    currentUserId: number | null,
) {
    const watchers = ref<Watcher[]>([]);

    const echo = (window as unknown as { Echo?: unknown }).Echo as
        | {
              join: (name: string) => {
                  here: (cb: (users: Watcher[]) => void) => unknown;
                  joining: (cb: (user: Watcher) => void) => unknown;
                  leaving: (cb: (user: Watcher) => void) => unknown;
              };
              leave: (name: string) => void;
          }
        | undefined;

    if (echo === undefined || recordId === '') {
        return { watchers };
    }

    const channelName = `app.presence.${appId}.${recordId}`;

    const withoutMe = (users: Watcher[]): Watcher[] =>
        users.filter((u) => u.id !== currentUserId);

    echo.join(channelName)
        .here((users) => (watchers.value = withoutMe(users)))
        .joining((user) => {
            if (user.id !== currentUserId)
                watchers.value = [...watchers.value, user];
        })
        .leaving((user) => {
            watchers.value = watchers.value.filter((u) => u.id !== user.id);
        });

    onBeforeUnmount(() => echo.leave(channelName));

    return { watchers };
}
