<script setup lang="ts">
import { computed } from 'vue';
import type { ObjectDef } from '../types/manifest';
import { useChartTooltip } from '../useChartTooltip';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';
import ChartTooltip from './ChartTooltip.vue';

interface FunnelStage {
    id: string;
    label: string;
    query: { object_id: string };
    aggregation: 'count' | 'sum' | 'avg';
    field_id?: string;
    color?: string;
}

interface FunnelBlock {
    id: string;
    type: 'funnel';
    label?: string;
    stages: FunnelStage[];
}

const props = defineProps<{
    block: FunnelBlock;
    data: { stages: Record<string, { value: number }> } | undefined;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

const t = themeTokens(useRuntimeTheme());
const { card, mouse, tip, onMove, showTip, hideTip } = useChartTooltip();

const palette = [
    '#3B82F6',
    '#6366F1',
    '#8B5CF6',
    '#A855F7',
    '#EC4899',
    '#F43F5E',
];

const stages = computed(() =>
    props.block.stages.map((s, i) => {
        const value = props.data?.stages?.[s.id]?.value ?? 0;
        return {
            id: s.id,
            label: s.label,
            value,
            color: s.color ?? palette[i % palette.length],
        };
    }),
);

const maxValue = computed(() =>
    Math.max(1, ...stages.value.map((s) => s.value)),
);

function formatNumber(value: number): string {
    return new Intl.NumberFormat(props.locale).format(
        Math.round(value * 100) / 100,
    );
}

function conversionFromFirst(value: number): string {
    const first = stages.value[0]?.value ?? 0;
    if (first === 0) return '—';
    return Math.round((value / first) * 100) + '%';
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
        <header v-if="block.label" class="mb-4">
            <p :class="['text-[11px] tracking-wider uppercase', t.textSubtle]">
                {{ block.label }}
            </p>
        </header>
        <ol class="space-y-1.5">
            <li
                v-for="(s, i) in stages"
                :key="s.id"
                class="cursor-pointer space-y-1 rounded-xs transition-opacity hover:opacity-90"
                @mouseenter="
                    showTip(
                        s.label,
                        formatNumber(s.value) +
                            (i > 0 ? ' · ' + conversionFromFirst(s.value) : ''),
                        s.color,
                    )
                "
            >
                <div class="flex items-center justify-between text-[11px]">
                    <span :class="t.text">{{ s.label }}</span>
                    <span :class="['tabular-nums', t.textMuted]">
                        {{ formatNumber(s.value) }}
                        <span v-if="i > 0" class="ml-1.5 text-[10px]"
                            >({{ conversionFromFirst(s.value) }})</span
                        >
                    </span>
                </div>
                <div
                    class="mx-auto"
                    :style="{ width: (s.value / maxValue) * 100 + '%' }"
                >
                    <div
                        class="h-7 rounded-xs"
                        :style="{ background: s.color }"
                    />
                </div>
            </li>
        </ol>
    </div>
</template>
