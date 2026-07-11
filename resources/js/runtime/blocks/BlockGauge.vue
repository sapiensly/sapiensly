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

// 180° arc over the TOP half, left → right. SVG's y axis points DOWN, so
// the top semicircle is θ ∈ [π, 2π] drawn with sweep=1 (visually clockwise);
// the previous sweep=0 path swept the BOTTOM half, which the 200×110
// viewBox clipped away — leaving only two round line-caps on screen.
const arc = computed(() => {
    const cx = 100,
        cy = 90,
        r = 70;
    const theta = Math.PI + Math.PI * ratio.value;
    const x1 = cx - r;
    const y1 = cy;
    const x2 = cx + r * Math.cos(theta);
    const y2 = cy + r * Math.sin(theta);
    return `M ${x1.toFixed(1)} ${y1.toFixed(1)} A ${r} ${r} 0 0 1 ${x2.toFixed(1)} ${y2.toFixed(1)}`;
});

const backgroundArc = computed(() => {
    const cx = 100,
        cy = 90,
        r = 70;
    // Full top semicircle, same sweep as the value arc.
    return `M ${cx - r} ${cy} A ${r} ${r} 0 0 1 ${cx + r} ${cy}`;
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
        // Percent measures usually arrive on the 0-100 scale (fcr_pct: 72.6,
        // max_value: 80) — Intl's percent style multiplies by 100 and printed
        // «7,263%». Only a true 0-1 ratio takes the multiplication.
        const alreadyScaled =
            props.block.max_value > 1.5 || Math.abs(value.value) > 1.5;
        return new Intl.NumberFormat(props.locale, {
            style: 'percent',
            maximumFractionDigits: 1,
        }).format(alreadyScaled ? value.value / 100 : value.value);
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
            / {{ new Intl.NumberFormat(locale).format(block.max_value)
            }}{{ block.format === 'percentage' ? '%' : '' }}
        </p>
    </div>
</template>
