<script setup lang="ts">
import type { BlockSpacer } from '../types/manifest';

const props = defineProps<{ block: BlockSpacer }>();

const heightClass = {
    sm: 'h-2',
    md: 'h-4',
    lg: 'h-8',
    xl: 'h-16',
}[props.block.size ?? 'md'];
</script>

<template>
    <div :class="heightClass" />
</template>
