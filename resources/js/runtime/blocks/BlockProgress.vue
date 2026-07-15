<script setup lang="ts">
import { computed } from 'vue';
import type { ObjectDef } from '../types/manifest';
import { useChartTooltip } from '../useChartTooltip';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';
import ChartTooltip from './ChartTooltip.vue';
import { formatPercent } from './formatPercent';

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
    data: { value: number; value_scale?: 'fraction' | 'unit' } | undefined;
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
const pct = computed(() => Math.round(ratio.value * 100));
// Default to the palette's first series (like its sibling gauge) so a goal bar
// follows palette_mode; an explicit block.color still wins.
const color = computed(() => props.block.color ?? 'var(--sp-chart-1, #3B82F6)');

function format(n: number): string {
    if (props.block.format === 'currency') {
        const obj = props.objects.find(
            (o) => o.id === props.block.query.object_id,
        );
        const field = obj?.fields.find((f) => f.id === props.block.field_id);
        const code = field?.currency_code ?? props.defaultCurrency ?? 'MXN';
        return new Intl.NumberFormat(props.locale, {
            style: 'currency',
            currency: code,
        }).format(n);
    }
    if (props.block.format === 'percentage') {
        return formatPercent(n, props.data?.value_scale, props.locale);
    }
    return new Intl.NumberFormat(props.locale).format(n);
}
</script>

<template>
    <div
        ref="card"
        :class="['relative rounded-sp-sm border p-5', t.surface]"
        @mousemove="onMove"
        @mouseleave="hideTip"
    >
        <ChartTooltip :tip="tip" :x="mouse.x" :y="mouse.y" />
        <div class="mb-2 flex items-baseline justify-between gap-3">
            <p
                v-if="block.label"
                :class="['text-[11px] tracking-wider uppercase', t.textSubtle]"
            >
                {{ block.label }}
            </p>
            <p :class="['text-xs', t.textMuted]">{{ pct }}%</p>
        </div>
        <div
            :class="[
                'h-2.5 w-full cursor-pointer overflow-hidden rounded-full',
                t.textSubtle,
            ]"
            style="
                background-color: currentColor;
                color: rgba(127, 127, 127, 0.2);
            "
            @mouseenter="
                showTip(
                    format(value),
                    pct + '% de ' + format(block.max_value),
                    color,
                )
            "
        >
            <div
                class="h-full rounded-full transition-[width] duration-500"
                :style="{ width: `${pct}%`, backgroundColor: color }"
            />
        </div>
        <div class="mt-2 flex items-baseline justify-between gap-3">
            <p :class="['text-lg font-semibold', t.text]">
                {{ format(value) }}
            </p>
            <p :class="['text-[11px]', t.textMuted]">
                / {{ format(block.max_value) }}
            </p>
        </div>
    </div>
</template>
