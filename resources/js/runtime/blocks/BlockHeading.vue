<script setup lang="ts">
import { computed } from 'vue';
import type { BlockHeading } from '../types/manifest';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';

// Drop the runtime props (block-data/objects/locale/…) AppRenderer passes to
// every block so they don't leak onto the DOM as stray attributes.
defineOptions({ inheritAttrs: false });

const props = defineProps<{ block: BlockHeading }>();

const t = themeTokens(useRuntimeTheme());

const tag = computed(() => `h${props.block.level ?? 2}` as 'h1' | 'h2' | 'h3' | 'h4' | 'h5' | 'h6');

// Website-appropriate type scale (responsive). `size` overrides the
// level-based default when the author wants a bigger/smaller heading.
const BY_LEVEL: Record<number, string> = {
    1: 'text-3xl sm:text-4xl font-bold tracking-tight',
    2: 'text-2xl sm:text-3xl font-bold tracking-tight',
    3: 'text-xl font-semibold',
    4: 'text-lg font-semibold',
    5: 'text-base font-semibold',
    6: 'text-xs font-semibold uppercase tracking-wider',
};
const BY_SIZE: Record<string, string> = {
    sm: 'text-base font-semibold',
    md: 'text-lg font-semibold',
    lg: 'text-xl font-semibold',
    xl: 'text-2xl sm:text-3xl font-bold tracking-tight',
    '2xl': 'text-3xl sm:text-4xl font-bold tracking-tight',
    display: 'text-4xl sm:text-5xl font-bold tracking-tight',
};

const sizeClass = computed(() => {
    const size = (props.block as { size?: string }).size;
    return (size && BY_SIZE[size]) || BY_LEVEL[props.block.level ?? 2] || BY_LEVEL[2];
});
</script>

<template>
    <!-- data-block-* are bound explicitly: inheritAttrs:false drops the ones
         AppRenderer passes, and the manual editor needs them here to select,
         edit and reorder the heading. -->
    <component
        :is="tag"
        :class="[t.text, sizeClass]"
        :data-block-id="block.id"
        :data-block-type="'heading'"
        >{{ block.content }}</component
    >
</template>
