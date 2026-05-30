<script setup lang="ts">
import type { BlockText } from '../types/manifest';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';

const props = defineProps<{ block: BlockText }>();

const t = themeTokens(useRuntimeTheme());

const sizeClass = {
    xs: 'text-xs',
    sm: 'text-sm',
    base: 'text-sm',
    lg: 'text-base',
    xl: 'text-lg',
}[props.block.size ?? 'base'];
</script>

<template>
    <p :class="[t.text, sizeClass]">{{ block.content }}</p>
</template>
