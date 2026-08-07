<script setup lang="ts">
import { Navigation, Square } from '@lucide/vue';
import { computed } from 'vue';
import { useTracking } from './useTracking';
import { runtimeWord } from './words';

/**
 * The line that says this is happening.
 *
 * Present the whole time a trail is being recorded, and not dismissible. A
 * banner somebody can close is a banner somebody closes, and then the app is
 * following them without their being reminded of it — which is the exact thing
 * this feature must never become.
 *
 * It also says the truth the web forces on us: this only runs with the app
 * open. Somebody who locks their phone expecting to be followed and finds a
 * hole in the trail was misled by us, not by their phone.
 */
const props = defineProps<{
    appSlug: string;
    locale: string;
    /** The record whose place the geofence is drawn around, when there is one. */
    recordId?: string;
}>();

const { active, pending, problem, start, stop } = useTracking(props.appSlug);

const message = computed(() => {
    if (problem.value === 'denied')
        return runtimeWord(props.locale, 'track_denied');
    if (problem.value === 'unsupported') {
        return runtimeWord(props.locale, 'track_unsupported');
    }
    if (problem.value === 'refused')
        return runtimeWord(props.locale, 'track_refused');

    return runtimeWord(props.locale, 'track_running');
});
</script>

<template>
    <button
        v-if="!active"
        type="button"
        data-sp-track-start
        class="inline-flex h-9 items-center gap-1.5 rounded-md border border-medium bg-surface px-3 text-xs text-ink-muted transition-colors hover:bg-surface-hover"
        @click="start(recordId)"
    >
        <Navigation class="size-3.5" />
        {{ runtimeWord(locale, 'track_start') }}
    </button>

    <!-- Not dismissible, and it says what is being done in plain words. -->
    <div
        v-else
        data-sp-track-bar
        role="status"
        class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-xs text-ink"
    >
        <span class="inline-flex items-center gap-1.5 font-medium">
            <span
                class="size-2 shrink-0 animate-pulse rounded-full bg-amber-500"
            />
            {{ message }}
        </span>

        <span class="text-[11px] text-ink-subtle">
            {{ runtimeWord(locale, 'track_only_open') }}
            <template v-if="pending > 0">
                · {{ runtimeWord(locale, 'track_pending', { n: pending }) }}
            </template>
        </span>

        <button
            type="button"
            data-sp-track-stop
            class="ml-auto inline-flex h-7 items-center gap-1.5 rounded-md border border-medium bg-surface px-2.5 text-[11px] text-ink transition-colors hover:bg-surface-hover"
            @click="stop"
        >
            <Square class="size-3" />
            {{ runtimeWord(locale, 'track_stop') }}
        </button>
    </div>
</template>
