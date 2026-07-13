<script setup lang="ts">
/**
 * Pulsing placeholder for a data-driven block while its deferred data loads —
 * sized by block type so the page doesn't reflow when real content lands.
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
        class="grid animate-pulse gap-3"
        :style="{
            gridTemplateColumns: `repeat(${kpiCount}, minmax(0, 1fr))`,
        }"
    >
        <div
            v-for="i in kpiCount"
            :key="i"
            class="h-28 rounded-sp-sm border border-medium bg-current/5"
        />
    </div>
    <div
        v-else-if="kind === 'stat' || kind === 'gauge' || kind === 'progress'"
        class="h-28 animate-pulse rounded-sp-sm border border-medium bg-current/5"
    />
    <div
        v-else-if="kind === 'table' || kind === 'data_grid' || kind === 'pivot'"
        class="animate-pulse space-y-2 rounded-sp-sm border border-medium p-4"
    >
        <div class="h-4 w-1/3 rounded-xs bg-current/10" />
        <div v-for="i in 5" :key="i" class="h-3 rounded-xs bg-current/5" />
    </div>
    <div
        v-else
        class="flex h-64 animate-pulse flex-col gap-3 rounded-sp-sm border border-medium p-5"
    >
        <div class="h-3 w-1/4 rounded-xs bg-current/10" />
        <div class="h-4 w-2/3 rounded-xs bg-current/10" />
        <div class="flex-1 rounded-sp-sm bg-current/5" />
    </div>
</template>
