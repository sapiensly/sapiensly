<script setup lang="ts">
import { computed } from 'vue';
import type { ObjectDef } from '../types/manifest';
import { useChartTooltip } from '../useChartTooltip';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';
import ChartTooltip from './ChartTooltip.vue';

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
const { card, mouse, tip, onMove, showTip, hideTip } = useChartTooltip();

const value = computed(() => Number(props.data?.value ?? 0));
const ratio = computed(() =>
    Math.min(1, Math.max(0, value.value / Math.max(1, props.block.max_value))),
);

// 180° arc going from -π to 0 (left to right, top half of a circle).
const arc = computed(() => {
    const cx = 100,
        cy = 90,
        r = 70;
    const start = Math.PI;
    const end = Math.PI - Math.PI * ratio.value;
    const x1 = cx + r * Math.cos(start);
    const y1 = cy + r * Math.sin(start);
    const x2 = cx + r * Math.cos(end);
    const y2 = cy + r * Math.sin(end);
    const large = 0; // half-circle max, never large arc
    const sweep = 0; // counter-clockwise visually (going right)
    return `M ${x1.toFixed(1)} ${y1.toFixed(1)} A ${r} ${r} 0 ${large} ${sweep} ${x2.toFixed(1)} ${y2.toFixed(1)}`;
});

const backgroundArc = computed(() => {
    const cx = 100,
        cy = 90,
        r = 70;
    // Full top semicircle from (-π) to 0.
    return `M ${cx - r} ${cy} A ${r} ${r} 0 0 0 ${cx + r} ${cy}`;
});

const color = computed(() => props.block.color ?? '#3B82F6');

const formatted = computed(() => {
    if (props.block.format === 'currency') {
        const obj = props.objects.find(
            (o) => o.id === props.block.query.object_id,
        );
        const field = obj?.fields.find((f) => f.id === props.block.field_id);
        const code = field?.currency_code ?? props.defaultCurrency ?? 'MXN';
        return new Intl.NumberFormat(props.locale, {
            style: 'currency',
            currency: code,
        }).format(value.value);
    }
    if (props.block.format === 'percentage') {
        return new Intl.NumberFormat(props.locale, {
            style: 'percent',
            maximumFractionDigits: 1,
        }).format(value.value);
    }
    return new Intl.NumberFormat(props.locale).format(value.value);
});
</script>

<template>
    <div
        ref="card"
        :class="[
            'relative flex flex-col items-center rounded-sp-sm border p-5',
            t.surface,
        ]"
        @mousemove="onMove"
        @mouseleave="hideTip"
    >
        <ChartTooltip :tip="tip" :x="mouse.x" :y="mouse.y" />
        <p
            v-if="block.label"
            :class="['mb-2 text-[11px] tracking-wider uppercase', t.textSubtle]"
        >
            {{ block.label }}
        </p>
        <svg
            viewBox="0 0 200 110"
            class="w-full max-w-[280px] cursor-pointer"
            @mouseenter="
                showTip(
                    formatted,
                    Math.round(ratio * 100) +
                        '% de ' +
                        new Intl.NumberFormat(locale).format(block.max_value),
                    color,
                )
            "
        >
            <path
                :d="backgroundArc"
                fill="none"
                stroke="currentColor"
                stroke-width="14"
                stroke-linecap="round"
                :class="t.textSubtle"
                stroke-opacity="0.25"
            />
            <path
                :d="arc"
                fill="none"
                :stroke="color"
                stroke-width="14"
                stroke-linecap="round"
            />
        </svg>
        <p :class="['-mt-4 text-2xl font-semibold', t.text]">{{ formatted }}</p>
        <p :class="['text-[11px]', t.textMuted]">
            / {{ new Intl.NumberFormat(locale).format(block.max_value) }}
        </p>
    </div>
</template>
