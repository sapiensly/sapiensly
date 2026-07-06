<script setup lang="ts">
import { computed } from 'vue';
import { resolveIcon } from './icons';

/**
 * Renders an app `icon` string: a known Lucide name → the icon component (sized
 * via `size`, inheriting currentColor); any other non-empty string → raw text at
 * the same visual size (so an emoji still renders). Empty → nothing. One
 * component everywhere a block shows an icon, so named icons work widely.
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

const component = computed(() => resolveIcon(props.name));

// The raw-text fallback exists for EMOJIS. A kebab-case ascii word is an icon
// NAME that failed to resolve — printing it ("thumbs-down" beside a KPI) is
// worse than showing nothing.
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
