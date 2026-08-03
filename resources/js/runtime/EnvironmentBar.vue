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
import { FlaskConical } from '@lucide/vue';
import { themeTokens, useRuntimeTheme } from './useRuntimeTheme';
import { runtimeWord } from './words';

const props = defineProps<{
    current: string;
    canSwitch: boolean;
    locale?: string;
}>();

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
            v-if="canSwitch"
            type="button"
            data-sp-environment-switch="production"
            class="ml-auto rounded-pill border border-amber-500/40 px-2.5 py-0.5 transition-colors hover:bg-amber-500/20"
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
