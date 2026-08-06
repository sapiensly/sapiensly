<script setup lang="ts">
/**
 * "No signal. This is what the app last saw, and when."
 *
 * A bar rather than a toast, for the reason EnvironmentBar is one: while it is
 * up, everything on the page is a snapshot, and somebody who forgets that acts
 * on a work order that was reassigned an hour ago. The cost of missing it is
 * asymmetric, so it stays on screen until the signal returns.
 *
 * It never appears while online. A permanent "you might be offline" notice is a
 * notice people stop reading.
 */
import { CloudOff } from '@lucide/vue';
import { computed } from 'vue';
import { describeAge, useOfflineStatus } from './useOfflineStatus';

const props = defineProps<{ locale?: string }>();

const { online, cachedAt } = useOfflineStatus();

const locale = computed(() => props.locale ?? 'en');
const spanish = computed(() => locale.value.startsWith('es'));

const headline = computed(() => (spanish.value ? 'Sin conexión' : 'No connection'));

/**
 * The age, when we know it.
 *
 * When we do not — a browser that refuses Cache Storage, a page that was never
 * cached — we say the weaker, true thing rather than inventing a time. Claiming
 * freshness we cannot vouch for is the exact failure this bar exists to prevent.
 */
const detail = computed(() => {
    const age = describeAge(cachedAt.value, locale.value);

    if (age) {
        return spanish.value ? `datos de ${age}` : `data from ${age}`;
    }

    return spanish.value
        ? 'estás viendo lo último que se descargó'
        : 'showing the last data downloaded';
});
</script>

<template>
    <div
        v-if="!online"
        data-sp-offline="1"
        role="status"
        aria-live="polite"
        class="mb-2 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border border-slate-500/40 bg-slate-500/10 px-3 py-1.5 text-xs text-slate-300"
    >
        <CloudOff class="size-3.5 shrink-0" />
        <span class="font-medium">{{ headline }}</span>
        <span class="opacity-80">{{ detail }}</span>
    </div>
</template>
