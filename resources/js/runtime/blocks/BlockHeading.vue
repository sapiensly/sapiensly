<script setup lang="ts">
import { computed } from 'vue';
import type { BlockHeading } from '../types/manifest';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';

const props = defineProps<{ block: BlockHeading }>();

const t = themeTokens(useRuntimeTheme());

const tag = computed(() => `h${props.block.level ?? 2}` as 'h1' | 'h2' | 'h3' | 'h4' | 'h5' | 'h6');

const sizeClass = computed(() => {
    return {
        1: 'text-[22px] font-semibold',
        2: 'text-lg font-semibold',
        3: 'text-base font-semibold',
        4: 'text-sm font-medium',
        5: 'text-sm font-medium',
        6: 'text-xs font-medium uppercase tracking-wider',
    }[props.block.level ?? 2];
});
</script>

<template>
    <component :is="tag" :class="[t.text, sizeClass]">{{ block.content }}</component>
</template>
