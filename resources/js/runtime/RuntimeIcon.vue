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
</script>

<template>
    <component
        :is="component"
        v-if="component"
        :size="size"
        :class="$props.class"
    />
    <span
        v-else-if="name"
        :class="$props.class"
        :style="{ fontSize: size + 'px', lineHeight: 1 }"
        >{{ name }}</span
    >
</template>
