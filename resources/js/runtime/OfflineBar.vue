<script setup lang="ts">
/**
 * The three things a person needs to know about a network they do not have.
 *
 *  1. There is no signal, and what is on screen is a snapshot from a time we
 *     name. (Phase 1.)
 *  2. N of their changes are held on this device, unsent.
 *  3. N of their changes were refused by the server and are not going to land.
 *
 * A bar rather than a toast, for the reason EnvironmentBar is one: while any of
 * these is true, everything on the page is a claim with a caveat, and somebody
 * who forgets that acts on a work order that was reassigned an hour ago.
 *
 * Note what it does NOT do: it never appears merely to say the connection is
 * fine. A permanent "you might be offline" notice is a notice people stop
 * reading, and this bar has to still be believed on the day it matters.
 */
import { CloudOff, Loader2, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useOfflineQueue } from './offlineQueue';
import { describeAge, useOfflineStatus } from './useOfflineStatus';
import { runtimeWord } from './words';

const props = defineProps<{ locale?: string }>();

const { online, cachedAt } = useOfflineStatus();
const { pendingCount, rejected, flushing, discardRejected } = useOfflineQueue();

const showRejected = ref(false);

const locale = computed(() => props.locale ?? 'en');
const word = (key: string, replace: Record<string, string | number> = {}) => runtimeWord(locale.value, key, replace);

/**
 * Unsent and refused work outlives the outage that produced it, so the bar
 * stays up after the signal returns until both are dealt with.
 */
const visible = computed(() => !online.value || pendingCount.value > 0 || rejected.value.length > 0);

/**
 * The age, when we know it.
 *
 * When we do not — a browser that refuses Cache Storage, a page that was never
 * cached — we say the weaker, true thing rather than inventing a time. Claiming
 * freshness we cannot vouch for is the exact failure this bar exists to prevent.
 */
const detail = computed(() => {
    const age = describeAge(cachedAt.value, locale.value);

    return age ? word('offline_data_from', { age }) : word('offline_last_seen');
});

const queuedLabel = computed(() =>
    pendingCount.value === 1 ? word('offline_queued_one') : word('offline_queued_many', { n: pendingCount.value }),
);

const rejectedLabel = computed(() =>
    rejected.value.length === 1
        ? word('offline_rejected_one')
        : word('offline_rejected_many', { n: rejected.value.length }),
);
</script>

<template>
    <div v-if="visible" data-sp-offline="1" role="status" aria-live="polite" class="mb-2 space-y-1.5">
        <div
            v-if="!online"
            class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border border-slate-500/40 bg-slate-500/10 px-3 py-1.5 text-xs text-slate-300"
        >
            <CloudOff class="size-3.5 shrink-0" />
            <span class="font-medium">{{ word('offline_headline') }}</span>
            <span class="opacity-80">{{ detail }}</span>
        </div>

        <div
            v-if="pendingCount > 0"
            class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border border-amber-500/40 bg-amber-500/10 px-3 py-1.5 text-xs text-amber-300"
        >
            <Loader2 v-if="flushing" class="size-3.5 shrink-0 animate-spin" />
            <CloudOff v-else class="size-3.5 shrink-0" />
            <span class="font-medium">{{ flushing ? word('offline_sending') : queuedLabel }}</span>
        </div>

        <div
            v-if="rejected.length > 0"
            class="rounded-md border border-red-500/40 bg-red-500/10 px-3 py-1.5 text-xs text-red-300"
        >
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <TriangleAlert class="size-3.5 shrink-0" />
                <span class="font-medium">{{ rejectedLabel }}</span>
                <span class="opacity-80">{{ word('offline_rejected_detail') }}</span>
                <button type="button" class="underline underline-offset-2" @click="showRejected = !showRejected">
                    {{ showRejected ? word('offline_rejected_hide') : word('offline_rejected_show') }}
                </button>
            </div>

            <!-- Named, not counted: "3 changes failed" tells somebody they lost
                 work without telling them which, which is the worst of both. -->
            <ul v-if="showRejected" class="mt-1.5 space-y-1 border-t border-red-500/20 pt-1.5">
                <li v-for="entry in rejected" :key="entry.id" class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                    <span class="font-mono opacity-90">{{ entry.label }}</span>
                    <span class="opacity-70">— {{ entry.reason }}</span>
                    <button
                        type="button"
                        class="underline underline-offset-2 opacity-70"
                        @click="discardRejected(entry.id)"
                    >
                        {{ word('offline_rejected_discard') }}
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>
