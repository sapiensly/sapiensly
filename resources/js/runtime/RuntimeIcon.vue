<script setup lang="ts">
import type { Component } from 'vue';
import { computed, ref, watchEffect } from 'vue';
import { resolveIcon, resolveIconLazy } from './icons';

/**
 * Renders an app `icon` string: a known Lucide name → the icon component (sized
 * via `size`, inheriting currentColor); any other non-empty string → raw text at
 * the same visual size (so an emoji still renders). Empty → nothing. One
 * component everywhere a block shows an icon, so named icons work widely.
 *
 * Two tiers: the curated REGISTRY resolves instantly (no flash — used for
 * common icons everywhere). Anything else is looked up against the FULL
 * Lucide set on demand; while that lookup is in flight the icon is simply
 * absent (never the raw name), then pops in once resolved. Not attempted on
 * the server (SSR renders the eager tier only; the client fills in the rest
 * on hydration) — acceptable since icons are decorative, not content.
 */
const props = withDefaults(
    defineProps<{
        name?: string | null;
        /** Pixel size for the icon (and matching font-size for an emoji). */
        size?: number;
        /** Extra classes (e.g. a colour) for the icon / emoji. */
        class?: string;
    }>(),
    { size: 20 },
);

const eager = computed(() => resolveIcon(props.name));
const lazy = ref<Component | null>(null);

watchEffect(() => {
    lazy.value = null;
    const name = props.name;
    if (eager.value || !name || typeof window === 'undefined') {
        return;
    }
    resolveIconLazy(name).then((resolved) => {
        if (props.name === name) {
            // still the same icon by the time the lazy chunk arrived
            lazy.value = resolved;
        }
    });
});

const component = computed(() => eager.value ?? lazy.value);

// The raw-text fallback exists for EMOJIS. A kebab-case ascii word is an icon
// NAME — either still resolving (lazy tier) or truly unknown — printing it
// ("thumbs-down" beside a KPI) is worse than showing nothing either way.
const fallbackText = computed(() => {
    const n = props.name?.trim();
    if (!n || component.value) {
        return null;
    }
    return /^[a-z0-9]+([-_ ][a-z0-9]+)*$/i.test(n) ? null : n;
});
</script>

<template>
    <component
        :is="component"
        v-if="component"
        :size="size"
        :class="$props.class"
    />
    <span
        v-else-if="fallbackText"
        :class="$props.class"
        :style="{ fontSize: size + 'px', lineHeight: 1 }"
        >{{ fallbackText }}</span
    >
</template>
