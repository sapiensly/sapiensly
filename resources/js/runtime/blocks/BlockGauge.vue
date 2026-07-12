<script setup lang="ts">
import GaugeChart from '@/components/charts/GaugeChart.vue';
import { computed } from 'vue';
import type { ObjectDef } from '../types/manifest';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';

interface GaugeBlock {
    id: string;
    type: 'gauge';
    label?: string;
    query: { object_id: string };
    aggregation: 'count' | 'sum' | 'avg' | 'min' | 'max';
    field_id?: string;
    max_value: number;
    format?: 'number' | 'currency' | 'percentage';
    color?: string;
}

const props = defineProps<{
    block: GaugeBlock;
    data: { value: number } | undefined;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

const t = themeTokens(useRuntimeTheme());
const value = computed(() => Number(props.data?.value ?? 0));
</script>

<template>
    <div :class="['flex flex-col rounded-sp-sm border p-5', t.surface]">
        <GaugeChart
            class="min-h-0 flex-1"
            :label="block.label ?? 'Meta'"
            :value="value"
            :target="block.max_value"
            :format="block.format === 'percentage' ? 'percentage' : 'number'"
            :accent="block.color ?? 'var(--sp-chart-1, #0059ff)'"
            :locale="locale"
        />
    </div>
</template>
