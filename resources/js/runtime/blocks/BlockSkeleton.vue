<script setup lang="ts">
/**
 * Loading placeholder for a data-driven block while its deferred data loads —
 * sized by block type so the page doesn't reflow when real content lands. Each
 * shape carries a live sheen (.sp-shimmer) so it reads as actively loading, and
 * the real block fades in over it (AppRenderer's .sp-block-in) once data arrives.
 */
import { computed } from 'vue';

const props = defineProps<{ block: { type: string; items?: unknown[] } }>();

const kind = computed(() => props.block.type);
const kpiCount = computed(() =>
    Math.min(6, Math.max(3, (props.block.items ?? []).length || 5)),
);
</script>

<template>
    <div
        v-if="kind === 'metric_grid'"
        class="grid gap-3"
        :style="{
            gridTemplateColumns: `repeat(${kpiCount}, minmax(0, 1fr))`,
        }"
    >
        <div
            v-for="i in kpiCount"
            :key="i"
            class="sp-shimmer h-28 rounded-sp-sm border border-medium"
        />
    </div>
    <div
        v-else-if="kind === 'stat' || kind === 'gauge' || kind === 'progress'"
        class="sp-shimmer h-28 rounded-sp-sm border border-medium"
    />
    <div
        v-else-if="kind === 'table' || kind === 'data_grid' || kind === 'pivot'"
        class="space-y-2 rounded-sp-sm border border-medium p-4"
    >
        <div class="sp-shimmer h-4 w-1/3 rounded-xs" />
        <div v-for="i in 5" :key="i" class="sp-shimmer h-3 rounded-xs" />
    </div>
    <div
        v-else
        class="flex h-64 flex-col gap-3 rounded-sp-sm border border-medium p-5"
    >
        <div class="sp-shimmer h-3 w-1/4 rounded-xs" />
        <div class="sp-shimmer h-4 w-2/3 rounded-xs" />
        <div class="sp-shimmer flex-1 rounded-sp-sm" />
    </div>
</template>
