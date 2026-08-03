<script setup lang="ts">
/**
 * "You are in the demo. Nothing here is real."
 *
 * Deliberately a BAR and not a quiet dropdown, for the reason RolePreviewBar is
 * one: while the sandbox is active every number on the page is fiction, and
 * somebody who forgets that reports the figures in a meeting. The cost of
 * missing it is asymmetric — reading demo data as though it were real is a
 * decision made on invented numbers.
 *
 * In production it is a single quiet link, because the way IN has to be
 * findable and a banner over real data every day is a banner people stop
 * seeing.
 *
 * Switching is a full page load: the environment is a server-side decision
 * remembered per app, so the server has to make it again.
 */
import { router } from '@inertiajs/vue3';
import { FlaskConical, RotateCcw } from '@lucide/vue';
import { ref } from 'vue';
import { themeTokens, useRuntimeTheme } from './useRuntimeTheme';
import { runtimeWord } from './words';

const props = defineProps<{
    current: string;
    canSwitch: boolean;
    locale?: string;
    /** Needed only to empty the sandbox. */
    appSlug?: string;
}>();

/**
 * Emptying the sandbox takes two clicks, on the bar itself.
 *
 * Not in a menu somewhere: a reset that can be reached while looking at
 * production is one wrong click from a business's records. The server refuses
 * it unless the session says demo, so this is the second lock, not the only
 * one.
 */
const confirming = ref(false);
const resetting = ref(false);

function reset(appSlug: string): void {
    if (!confirming.value) {
        confirming.value = true;
        return;
    }

    confirming.value = false;
    resetting.value = true;
    router.post(
        `/r/${appSlug}/environment/reset`,
        {},
        { onFinish: () => (resetting.value = false) },
    );
}

function switchTo(environment: string): void {
    const url = new URL(window.location.href);
    url.searchParams.set('env', environment);
    window.location.href = url.toString();
}

function word(key: string): string {
    return runtimeWord(props.locale, key);
}

const t = themeTokens(useRuntimeTheme());
</script>

<template>
    <div
        v-if="current === 'demo'"
        data-sp-environment="demo"
        class="mb-2 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border border-amber-500/40 bg-amber-500/10 px-3 py-1.5 text-xs text-amber-300"
    >
        <FlaskConical class="size-3.5 shrink-0" />
        <span class="font-medium">{{ word('demo_banner') }}</span>
        <span class="opacity-80">{{ word('demo_explains') }}</span>
        <button
            v-if="canSwitch && appSlug"
            type="button"
            data-sp-environment-reset
            :disabled="resetting"
            class="ml-auto rounded-pill border border-amber-500/40 px-2.5 py-0.5 transition-colors hover:bg-amber-500/20 disabled:opacity-50"
            @click="reset(appSlug)"
        >
            <RotateCcw class="mr-1 inline size-3" />
            {{ confirming ? word('demo_reset_sure') : word('demo_reset') }}
        </button>
        <button
            v-if="canSwitch"
            type="button"
            data-sp-environment-switch="production"
            class="rounded-pill border border-amber-500/40 px-2.5 py-0.5 transition-colors hover:bg-amber-500/20"
            :class="!appSlug && 'ml-auto'"
            @click="switchTo('production')"
        >
            {{ word('demo_leave') }}
        </button>
    </div>

    <div
        v-else-if="canSwitch"
        data-sp-environment="production"
        :class="['mb-2 flex justify-end text-[11px]', t.textSubtle]"
    >
        <button
            type="button"
            data-sp-environment-switch="demo"
            class="inline-flex items-center gap-1.5 rounded-pill px-2 py-0.5 transition-colors hover:opacity-100"
            @click="switchTo('demo')"
        >
            <FlaskConical class="size-3" />
            {{ word('demo_enter') }}
        </button>
    </div>
</template>
