<script setup lang="ts">
import { computed } from 'vue';
import type { ObjectDef } from '../types/manifest';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';
import { computeTrend } from './trend';

interface MetricItem {
    id: string;
    label: string;
    query: { object_id: string };
    aggregation: 'count' | 'sum' | 'avg' | 'min' | 'max';
    field_id?: string;
    format?: 'number' | 'currency' | 'percentage' | 'duration';
    icon?: string;
    delta_good?: 'up' | 'down';
}

interface MetricGridBlock {
    id: string;
    type: 'metric_grid';
    columns?: number;
    items: MetricItem[];
}

const props = defineProps<{
    block: MetricGridBlock;
    data: { items: Record<string, { value: number; compare_value?: number }> } | undefined;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

function trendFor(item: MetricItem) {
    const entry = props.data?.items?.[item.id];
    return computeTrend(entry?.value ?? 0, entry?.compare_value, item.delta_good ?? 'up');
}

const t = themeTokens(useRuntimeTheme());

const gridClass = computed(() => {
    const cols = props.block.columns ?? 3;
    return {
        1: 'grid-cols-1',
        2: 'grid-cols-1 sm:grid-cols-2',
        3: 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3',
        4: 'grid-cols-2 lg:grid-cols-4',
        5: 'grid-cols-2 lg:grid-cols-5',
        6: 'grid-cols-2 md:grid-cols-3 lg:grid-cols-6',
    }[cols] ?? 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3';
});

function format(item: MetricItem, raw: number | undefined): string {
    const v = raw ?? 0;
    if (item.format === 'currency') {
        const obj = props.objects.find((o) => o.id === item.query.object_id);
        const field = obj?.fields.find((f) => f.id === item.field_id);
        const code = field?.currency_code ?? props.defaultCurrency ?? 'MXN';
        return new Intl.NumberFormat(props.locale, { style: 'currency', currency: code }).format(v);
    }
    if (item.format === 'percentage') {
        return new Intl.NumberFormat(props.locale, { style: 'percent', maximumFractionDigits: 1 }).format(v);
    }
    return new Intl.NumberFormat(props.locale).format(v);
}
</script>

<template>
    <div :class="['grid gap-3', gridClass]">
        <div
            v-for="item in block.items"
            :key="item.id"
            :class="['rounded-sp-sm border p-5', t.surface]"
        >
            <div class="flex items-start justify-between gap-2">
                <p :class="['text-[11px] uppercase tracking-wider', t.textSubtle]">{{ item.label }}</p>
                <span v-if="item.icon" class="text-base leading-none">{{ item.icon }}</span>
            </div>
            <p :class="['mt-2 text-2xl font-bold tracking-tight', t.statTint]">
                {{ format(item, data?.items?.[item.id]?.value) }}
            </p>
            <p
                v-if="trendFor(item)"
                class="mt-1 inline-flex items-center gap-1 text-xs font-semibold"
                :class="trendFor(item)!.dir === 'flat' ? t.textSubtle : trendFor(item)!.good ? 'text-emerald-500' : 'text-red-500'"
            >
                <span v-if="trendFor(item)!.dir === 'up'">▲</span><span v-else-if="trendFor(item)!.dir === 'down'">▼</span><span v-else>→</span>
                {{ trendFor(item)!.label }}
            </p>
        </div>
    </div>
</template>
