<script setup lang="ts">
import { computed } from 'vue';
import type { FieldDef, ObjectDef } from '../types/manifest';
import { resolveField } from '../types/manifest';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';

interface ChartBlock {
    id: string;
    type: 'chart';
    label?: string;
    chart_type: 'bar' | 'hbar' | 'line' | 'area' | 'pie' | 'donut';
    data_source: { object_id: string };
    x_field_id?: string;
    y_field_id?: string;
    group_by_field_id?: string;
    aggregation: 'count' | 'sum' | 'avg' | 'min' | 'max';
}

interface RowData {
    id: string;
    data: Record<string, unknown>;
}

const props = defineProps<{
    block: ChartBlock;
    data: { rows: RowData[] } | undefined;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

const t = themeTokens(useRuntimeTheme());

const object = computed<ObjectDef | undefined>(() =>
    props.objects.find((o) => o.id === props.block.data_source.object_id),
);

function fieldOf(id: string | undefined): FieldDef | undefined {
    return resolveField(object.value, id);
}

const groupField = computed(() => fieldOf(props.block.group_by_field_id ?? props.block.x_field_id));
const yField = computed(() => fieldOf(props.block.y_field_id));

// Aggregate rows into [{label, value}] series.
const series = computed<{ label: string; value: number }[]>(() => {
    const rows = props.data?.rows ?? [];
    const groupSlug = groupField.value?.slug;
    const ySlug = yField.value?.slug;
    const agg = props.block.aggregation;

    // Bucket: groupValue → numeric array
    const buckets = new Map<string, number[]>();
    for (const r of rows) {
        const groupRaw = groupSlug ? r.data[groupSlug] : 'all';
        const key = formatGroupKey(groupRaw, groupField.value);
        const yRaw = ySlug ? Number(r.data[ySlug] ?? 0) : 1;
        if (!buckets.has(key)) buckets.set(key, []);
        buckets.get(key)!.push(Number.isFinite(yRaw) ? yRaw : 0);
    }

    const out: { label: string; value: number }[] = [];
    for (const [label, values] of buckets) {
        let value: number;
        switch (agg) {
            case 'count':
                value = values.length;
                break;
            case 'sum':
                value = values.reduce((a, b) => a + b, 0);
                break;
            case 'avg':
                value = values.length === 0 ? 0 : values.reduce((a, b) => a + b, 0) / values.length;
                break;
            case 'min':
                value = values.length === 0 ? 0 : Math.min(...values);
                break;
            case 'max':
                value = values.length === 0 ? 0 : Math.max(...values);
                break;
            default:
                value = values.length;
        }
        out.push({ label, value });
    }
    return out;
});

function formatGroupKey(value: unknown, field: FieldDef | undefined): string {
    if (value === null || value === undefined || value === '') return '—';
    if (field?.type === 'single_select') {
        const opt = field.options?.find((o) => o.value === value);
        return opt?.label ?? String(value);
    }
    return String(value);
}

function colorFor(label: string, index: number): string {
    if (groupField.value?.type === 'single_select') {
        const opt = groupField.value.options?.find((o) => o.label === label || o.value === label);
        if (opt?.color) return opt.color;
    }
    const palette = ['#3B82F6', '#10B981', '#F59E0B', '#EC4899', '#8B5CF6', '#06B6D4', '#F97316', '#84CC16'];
    return palette[index % palette.length];
}

const maxValue = computed(() => Math.max(1, ...series.value.map((s) => s.value)));
const totalValue = computed(() => series.value.reduce((a, s) => a + s.value, 0));

function formatNumber(value: number): string {
    return new Intl.NumberFormat(props.locale).format(Math.round(value * 100) / 100);
}

// Pie/donut helpers
interface PieSlice {
    label: string;
    value: number;
    color: string;
    path: string;
    percent: number;
}

const pieSlices = computed<PieSlice[]>(() => {
    if (totalValue.value === 0) return [];
    const cx = 80;
    const cy = 80;
    const r = 72;
    let cursor = -Math.PI / 2;
    return series.value.map((s, i) => {
        const angle = (s.value / totalValue.value) * Math.PI * 2;
        const x1 = cx + r * Math.cos(cursor);
        const y1 = cy + r * Math.sin(cursor);
        cursor += angle;
        const x2 = cx + r * Math.cos(cursor);
        const y2 = cy + r * Math.sin(cursor);
        const large = angle > Math.PI ? 1 : 0;
        const path = `M ${cx} ${cy} L ${x1} ${y1} A ${r} ${r} 0 ${large} 1 ${x2} ${y2} Z`;
        return {
            label: s.label,
            value: s.value,
            color: colorFor(s.label, i),
            path,
            percent: s.value / totalValue.value,
        };
    });
});

// Line path for line/area charts
const linePath = computed(() => {
    const w = 320;
    const h = 140;
    const padL = 8;
    const padR = 8;
    const padT = 8;
    const padB = 24;
    const n = series.value.length;
    if (n === 0) return { line: '', area: '', points: [] as Array<{ x: number; y: number; label: string; value: number }> };
    const innerW = w - padL - padR;
    const innerH = h - padT - padB;
    const stepX = n === 1 ? 0 : innerW / (n - 1);
    const points = series.value.map((s, i) => {
        const x = padL + (n === 1 ? innerW / 2 : i * stepX);
        const y = padT + innerH - (s.value / maxValue.value) * innerH;
        return { x, y, label: s.label, value: s.value };
    });
    const line = points.map((p, i) => (i === 0 ? `M ${p.x} ${p.y}` : `L ${p.x} ${p.y}`)).join(' ');
    const area =
        line +
        ` L ${points[points.length - 1].x} ${padT + innerH}` +
        ` L ${points[0].x} ${padT + innerH} Z`;
    return { line, area, points };
});
</script>

<template>
    <div :class="['rounded-sp-sm border p-5', t.surface]">
        <header v-if="block.label" class="mb-3 flex items-center justify-between">
            <p :class="['text-[11px] uppercase tracking-wider', t.textSubtle]">{{ block.label }}</p>
        </header>

        <p v-if="series.length === 0" :class="['py-8 text-center text-xs', t.textMuted]">
            No data to plot.
        </p>

        <template v-else-if="block.chart_type === 'pie' || block.chart_type === 'donut'">
            <div class="flex items-center gap-6">
                <svg viewBox="0 0 160 160" class="size-32 shrink-0">
                    <path
                        v-for="(slice, i) in pieSlices"
                        :key="i"
                        :d="slice.path"
                        :fill="slice.color"
                        stroke="rgba(0,0,0,0.15)"
                        stroke-width="0.5"
                    />
                    <circle v-if="block.chart_type === 'donut'" cx="80" cy="80" r="36" fill="var(--sp-navy, #0b1530)" />
                </svg>
                <ul class="flex-1 space-y-1 text-xs">
                    <li v-for="(slice, i) in pieSlices" :key="i" class="flex items-center justify-between gap-2">
                        <span class="flex items-center gap-2 min-w-0">
                            <span class="size-2.5 shrink-0 rounded-xs" :style="{ background: slice.color }" />
                            <span class="truncate" :class="t.text">{{ slice.label }}</span>
                        </span>
                        <span :class="t.textMuted">
                            {{ formatNumber(slice.value) }}
                            <span class="ml-1 text-[10px] opacity-70">{{ Math.round(slice.percent * 100) }}%</span>
                        </span>
                    </li>
                </ul>
            </div>
        </template>

        <template v-else-if="block.chart_type === 'line' || block.chart_type === 'area'">
            <svg viewBox="0 0 320 140" class="w-full" preserveAspectRatio="none">
                <path
                    v-if="block.chart_type === 'area'"
                    :d="linePath.area"
                    fill="#3B82F6"
                    fill-opacity="0.15"
                />
                <path
                    :d="linePath.line"
                    fill="none"
                    stroke="#3B82F6"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />
                <circle
                    v-for="(p, i) in linePath.points"
                    :key="i"
                    :cx="p.x"
                    :cy="p.y"
                    r="3"
                    fill="#3B82F6"
                />
            </svg>
            <ul class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-[11px]" :class="t.textMuted">
                <li v-for="(p, i) in linePath.points" :key="i" class="flex items-center gap-1">
                    <span class="font-medium" :class="t.text">{{ p.label }}</span>
                    <span>{{ formatNumber(p.value) }}</span>
                </li>
            </ul>
        </template>

        <template v-else-if="block.chart_type === 'hbar'">
            <!-- hbar = horizontal bars (progress-style) -->
            <ul class="space-y-2">
                <li
                    v-for="(s, i) in series"
                    :key="s.label"
                    class="space-y-1"
                >
                    <div class="flex items-center justify-between text-[11px]">
                        <span :class="t.text">{{ s.label }}</span>
                        <span :class="['tabular-nums', t.textMuted]">{{ formatNumber(s.value) }}</span>
                    </div>
                    <div class="h-2 w-full overflow-hidden rounded-pill bg-white/5">
                        <div
                            class="h-full rounded-pill transition-all"
                            :style="{
                                width: (s.value / maxValue) * 100 + '%',
                                background: colorFor(s.label, i),
                            }"
                        />
                    </div>
                </li>
            </ul>
        </template>

        <template v-else>
            <!-- bar = vertical columns -->
            <div class="flex h-48 items-end gap-3 px-2 pt-2">
                <div
                    v-for="(s, i) in series"
                    :key="s.label"
                    class="flex h-full min-w-0 flex-1 flex-col items-stretch justify-end gap-1"
                >
                    <span :class="['text-center text-[11px] tabular-nums', t.textMuted]">
                        {{ formatNumber(s.value) }}
                    </span>
                    <div
                        class="rounded-t-xs transition-all"
                        :style="{
                            height: Math.max(2, (s.value / maxValue) * 100) + '%',
                            background: colorFor(s.label, i),
                        }"
                        :title="s.label + ': ' + formatNumber(s.value)"
                    />
                </div>
            </div>
            <div class="flex gap-3 px-2 pt-1">
                <div
                    v-for="(s, i) in series"
                    :key="s.label"
                    :class="['min-w-0 flex-1 truncate text-center text-[11px]', t.text]"
                    :title="s.label"
                >
                    {{ s.label }}
                </div>
            </div>
        </template>
    </div>
</template>
