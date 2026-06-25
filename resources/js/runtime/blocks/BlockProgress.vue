<script setup lang="ts">
import { computed } from 'vue';
import type { ObjectDef } from '../types/manifest';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';

interface ProgressBlock {
    id: string;
    type: 'progress';
    label?: string;
    query: { object_id: string };
    aggregation: 'count' | 'sum' | 'avg' | 'min' | 'max';
    field_id?: string;
    max_value: number;
    format?: 'number' | 'currency' | 'percentage';
    color?: string;
}

const props = defineProps<{
    block: ProgressBlock;
    data: { value: number } | undefined;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

const t = themeTokens(useRuntimeTheme());

const value = computed(() => Number(props.data?.value ?? 0));
const ratio = computed(() => Math.min(1, Math.max(0, value.value / Math.max(1, props.block.max_value))));
const pct = computed(() => Math.round(ratio.value * 100));
const color = computed(() => props.block.color ?? '#3B82F6');

function format(n: number): string {
    if (props.block.format === 'currency') {
        const obj = props.objects.find((o) => o.id === props.block.query.object_id);
        const field = obj?.fields.find((f) => f.id === props.block.field_id);
        const code = field?.currency_code ?? props.defaultCurrency ?? 'MXN';
        return new Intl.NumberFormat(props.locale, { style: 'currency', currency: code }).format(n);
    }
    if (props.block.format === 'percentage') {
        return new Intl.NumberFormat(props.locale, { style: 'percent', maximumFractionDigits: 1 }).format(n);
    }
    return new Intl.NumberFormat(props.locale).format(n);
}
</script>

<template>
    <div :class="['rounded-sp-sm border p-5', t.surface]">
        <div class="mb-2 flex items-baseline justify-between gap-3">
            <p v-if="block.label" :class="['text-[11px] uppercase tracking-wider', t.textSubtle]">{{ block.label }}</p>
            <p :class="['text-xs', t.textMuted]">{{ pct }}%</p>
        </div>
        <div :class="['h-2.5 w-full overflow-hidden rounded-full', t.textSubtle]" style="background-color: currentColor; color: rgba(127,127,127,0.2)">
            <div class="h-full rounded-full transition-[width] duration-500" :style="{ width: `${pct}%`, backgroundColor: color }" />
        </div>
        <div class="mt-2 flex items-baseline justify-between gap-3">
            <p :class="['text-lg font-semibold', t.text]">{{ format(value) }}</p>
            <p :class="['text-[11px]', t.textMuted]">/ {{ format(block.max_value) }}</p>
        </div>
    </div>
</template>
