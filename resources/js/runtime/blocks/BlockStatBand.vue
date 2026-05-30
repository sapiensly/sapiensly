<script setup lang="ts">
import { computed } from 'vue';

interface StatItem { id?: string; value: string; label?: string }
interface StatBandBlock { id: string; type: 'stat_band'; items: StatItem[] }

defineOptions({ inheritAttrs: false });

const props = defineProps<{ block: StatBandBlock }>();

const colsClass = computed(() => {
    const n = Math.min(props.block.items.length, 4);
    return { 1: 'sm:grid-cols-1', 2: 'sm:grid-cols-2', 3: 'sm:grid-cols-3', 4: 'sm:grid-cols-2 lg:grid-cols-4' }[n] ?? 'sm:grid-cols-3';
});
</script>

<template>
    <div :class="['grid grid-cols-1 gap-6', colsClass]">
        <div v-for="(item, i) in block.items" :key="item.id ?? i" class="text-center">
            <div class="text-4xl font-bold tracking-tight sm:text-5xl" :style="{ color: 'var(--sp-accent, currentColor)' }">{{ item.value }}</div>
            <div v-if="item.label" class="mt-1 text-sm" :style="{ opacity: 0.75 }">{{ item.label }}</div>
        </div>
    </div>
</template>
