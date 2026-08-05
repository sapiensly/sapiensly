<script setup lang="ts">
import type { Component } from 'vue';
import { computed } from 'vue';

/**
 * A single tile in the Stack screen. Composition: icon + name + version
 * pill + description + status dot with tooltip on hover. If `docsUrl` is
 * provided the whole tile becomes a link.
 */
interface Props {
    name: string;
    version: string;
    description: string;
    status: 'ok' | 'outdated' | 'missing';
    docsUrl?: string;
    icon?: Component;
}

const props = defineProps<Props>();

/**
 * Keyed on the status union, so adding a state to Props is a type error here
 * rather than a dot that silently renders with no colour.
 */
const dotMap: Record<Props['status'], { color: string; title: string }> = {
    ok: { color: 'var(--sp-success)', title: 'Running' },
    outdated: {
        color: 'var(--sp-warning)',
        title: 'Degraded / not reachable',
    },
    missing: { color: 'var(--sp-danger)', title: 'Missing' },
};

const dot = computed(() => dotMap[props.status]);
</script>

<template>
    <component
        :is="docsUrl ? 'a' : 'div'"
        :href="docsUrl"
        :target="docsUrl ? '_blank' : undefined"
        :rel="docsUrl ? 'noreferrer' : undefined"
        class="group flex flex-col gap-2 rounded-xs border border-soft bg-navy p-4 transition-colors hover:border-medium hover:bg-white/[0.04]"
    >
        <header class="flex items-start justify-between gap-2">
            <div class="flex items-center gap-2">
                <component
                    :is="icon"
                    v-if="icon"
                    class="size-4 text-ink-subtle"
                />
                <h3 class="text-sm font-medium text-ink">{{ name }}</h3>
            </div>
            <span
                class="mt-1 inline-block size-2 shrink-0 rounded-pill"
                :style="{ backgroundColor: dot.color }"
                :title="dot.title"
                :aria-label="dot.title"
            />
        </header>

        <p class="font-mono text-[10px] text-ink-muted">v{{ version }}</p>

        <p class="text-xs text-ink-muted">{{ description }}</p>
    </component>
</template>
