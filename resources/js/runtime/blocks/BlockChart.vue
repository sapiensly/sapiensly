<script setup lang="ts">
import { computed } from 'vue';
import type { FieldDef, ObjectDef } from '../types/manifest';
import { resolveField } from '../types/manifest';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';

interface ChartBlock {
    id: string;
    type: 'chart';
    label?: string;
    chart_type: 'bar' | 'hbar' | 'line' | 'area' | 'pie' | 'donut' | 'radar' | 'scatter' | 'treemap';
    data_source: { object_id: string };
    x_field_id?: string;
    y_field_id?: string;
    group_by_field_id?: string;
    series_field_id?: string;
    stacked?: boolean;
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

// Pick a colour for a value of an arbitrary field (honours single_select option
// colours), used for the series legend in stacked/grouped charts.
function paletteColorFor(field: FieldDef | undefined, label: string, index: number): string {
    if (field?.type === 'single_select') {
        const opt = field.options?.find((o) => o.label === label || o.value === label);
        if (opt?.color) return opt.color;
    }
    const palette = ['#3B82F6', '#10B981', '#F59E0B', '#EC4899', '#8B5CF6', '#06B6D4', '#F97316', '#84CC16'];
    return palette[index % palette.length];
}

const seriesField = computed(() => fieldOf(props.block.series_field_id));

// Multi-series bar: split each category (group_by/x) into segments by a SECOND
// field (series_field_id), then stack or group them. Two-dimensional aggregation
// over the raw rows — only meaningful for bar charts.
const isMultiSeries = computed(() => !!seriesField.value && props.block.chart_type === 'bar');

const multi = computed(() => {
    if (!isMultiSeries.value) return null;
    const rows = props.data?.rows ?? [];
    const catField = groupField.value;
    const serField = seriesField.value;
    if (!catField || !serField) return null;
    const catSlug = catField.slug;
    const serSlug = serField.slug;
    const ySlug = yField.value?.slug;
    const agg = props.block.aggregation;

    const cats: string[] = [];
    const sers: string[] = [];
    const catIndex = new Map<string, number>();
    const serIndex = new Map<string, number>();
    const bucket: number[][][] = []; // [catIdx][serIdx] = numeric values
    for (const r of rows) {
        const ck = formatGroupKey(r.data[catSlug], catField);
        const sk = formatGroupKey(r.data[serSlug], serField);
        let ci = catIndex.get(ck);
        if (ci === undefined) { ci = cats.length; cats.push(ck); catIndex.set(ck, ci); }
        let si = serIndex.get(sk);
        if (si === undefined) { si = sers.length; sers.push(sk); serIndex.set(sk, si); }
        (bucket[ci] ??= [])[si] ??= [];
        const yRaw = ySlug ? Number(r.data[ySlug] ?? 0) : 1;
        bucket[ci][si].push(Number.isFinite(yRaw) ? yRaw : 0);
    }

    const aggregate = (vals: number[] | undefined): number => {
        if (!vals || vals.length === 0) return 0;
        switch (agg) {
            case 'sum': return vals.reduce((a, b) => a + b, 0);
            case 'avg': return vals.reduce((a, b) => a + b, 0) / vals.length;
            case 'min': return Math.min(...vals);
            case 'max': return Math.max(...vals);
            default: return vals.length; // count
        }
    };

    const data = cats.map((_, ci) => sers.map((_, si) => aggregate(bucket[ci]?.[si])));
    const stacked = !!props.block.stacked;
    const max = stacked
        ? Math.max(1, ...data.map((row) => row.reduce((a, b) => a + b, 0)))
        : Math.max(1, ...data.flat());
    const series = sers.map((label, i) => ({ label, color: paletteColorFor(serField, label, i) }));
    return { cats, series, data, max, stacked };
});

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

// Radar: plot the categorical buckets on radial axes (needs >= 3 axes).
const radar = computed(() => {
    const n = series.value.length;
    if (n < 3) return null;
    const cx = 110;
    const cy = 110;
    const r = 84;
    const max = maxValue.value;
    const axes = series.value.map((s, i) => {
        const ang = -Math.PI / 2 + (i / n) * Math.PI * 2;
        const rr = (s.value / max) * r;
        return {
            label: s.label,
            ax: cx + r * Math.cos(ang),
            ay: cy + r * Math.sin(ang),
            px: cx + rr * Math.cos(ang),
            py: cy + rr * Math.sin(ang),
            lx: cx + (r + 16) * Math.cos(ang),
            ly: cy + (r + 16) * Math.sin(ang),
        };
    });
    const poly = axes.map((a, i) => `${i === 0 ? 'M' : 'L'} ${a.px.toFixed(1)} ${a.py.toFixed(1)}`).join(' ') + ' Z';
    return { cx, cy, r, axes, poly };
});

// Treemap: squarified rectangles sized by each bucket's value (segmentation).
const treemap = computed(() => {
    const total = totalValue.value;
    if (total === 0) return null;
    const W = 320;
    const H = 200;
    const data = [...series.value].filter((s) => s.value > 0).sort((a, b) => b.value - a.value);
    if (data.length === 0) return null;

    const rects: { x: number; y: number; w: number; h: number; label: string; value: number; color: string }[] = [];
    // Simple squarified layout: pack rows along the shorter side.
    let x = 0;
    let y = 0;
    let w = W;
    let h = H;
    let i = 0;
    let remaining = total;
    while (i < data.length) {
        const horizontal = w >= h;
        const side = horizontal ? h : w;
        // Greedily grow a row while it keeps aspect ratios reasonable.
        let row: typeof data = [];
        let rowSum = 0;
        let bestRatio = Infinity;
        let j = i;
        while (j < data.length) {
            const trySum = rowSum + data[j].value;
            const length = (trySum / remaining) * (horizontal ? w : h);
            const next = [...row, data[j]];
            const worst = Math.max(
                ...next.map((d) => {
                    const cell = (d.value / trySum) * side;
                    return Math.max(length / cell, cell / length);
                }),
            );
            if (worst > bestRatio && row.length > 0) break;
            bestRatio = worst;
            row = next;
            rowSum = trySum;
            j++;
        }
        const rowLen = (rowSum / remaining) * (horizontal ? w : h);
        let off = 0;
        for (const d of row) {
            const cell = (d.value / rowSum) * side;
            const idx = data.indexOf(d);
            rects.push({
                x: horizontal ? x : x + off,
                y: horizontal ? y + off : y,
                w: horizontal ? rowLen : cell,
                h: horizontal ? cell : rowLen,
                label: d.label,
                value: d.value,
                color: colorFor(d.label, idx),
            });
            off += cell;
        }
        if (horizontal) { x += rowLen; w -= rowLen; } else { y += rowLen; h -= rowLen; }
        remaining -= rowSum;
        i = j;
    }
    return { W, H, rects };
});

// Scatter: plot raw (x_field, y_field) points from each row.
const scatter = computed(() => {
    const rows = props.data?.rows ?? [];
    const xSlug = fieldOf(props.block.x_field_id)?.slug;
    const ySlug = yField.value?.slug;
    if (!xSlug || !ySlug) return null;
    const pts = rows
        .map((row) => ({ x: Number(row.data[xSlug]), y: Number(row.data[ySlug]) }))
        .filter((p) => Number.isFinite(p.x) && Number.isFinite(p.y));
    if (pts.length === 0) return null;
    const xs = pts.map((p) => p.x);
    const ys = pts.map((p) => p.y);
    const [minX, maxX, minY, maxY] = [Math.min(...xs), Math.max(...xs), Math.min(...ys), Math.max(...ys)];
    const w = 320;
    const h = 180;
    const pad = 18;
    const sx = (x: number) => (maxX === minX ? w / 2 : pad + ((x - minX) / (maxX - minX)) * (w - 2 * pad));
    const sy = (y: number) => (maxY === minY ? h / 2 : h - pad - ((y - minY) / (maxY - minY)) * (h - 2 * pad));
    return { w, h, points: pts.map((p) => ({ cx: sx(p.x), cy: sy(p.y) })) };
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

        <template v-else-if="block.chart_type === 'radar' && radar">
            <svg viewBox="0 0 220 220" class="mx-auto w-full max-w-[280px]" :class="t.text">
                <circle
                    v-for="ring in [0.34, 0.67, 1]"
                    :key="ring"
                    :cx="radar.cx" :cy="radar.cy" :r="radar.r * ring"
                    fill="none" stroke="currentColor" stroke-opacity="0.12"
                />
                <line
                    v-for="(a, i) in radar.axes" :key="'ax' + i"
                    :x1="radar.cx" :y1="radar.cy" :x2="a.ax" :y2="a.ay"
                    stroke="currentColor" stroke-opacity="0.12"
                />
                <path :d="radar.poly" :style="{ fill: 'var(--sp-accent, #3B82F6)', stroke: 'var(--sp-accent, #3B82F6)' }" fill-opacity="0.2" stroke-width="2" />
                <circle v-for="(a, i) in radar.axes" :key="'pt' + i" :cx="a.px" :cy="a.py" r="3" :style="{ fill: 'var(--sp-accent, #3B82F6)' }" />
                <text
                    v-for="(a, i) in radar.axes" :key="'lb' + i"
                    :x="a.lx" :y="a.ly" text-anchor="middle" dominant-baseline="middle"
                    fill="currentColor" fill-opacity="0.7" style="font-size: 8px"
                >{{ a.label }}</text>
            </svg>
        </template>

        <template v-else-if="block.chart_type === 'treemap' && treemap">
            <svg :viewBox="`0 0 ${treemap.W} ${treemap.H}`" class="w-full">
                <g v-for="(r, i) in treemap.rects" :key="i">
                    <rect :x="r.x + 1" :y="r.y + 1" :width="Math.max(0, r.w - 2)" :height="Math.max(0, r.h - 2)" :fill="r.color" rx="2" />
                    <text v-if="r.w > 44 && r.h > 22" :x="r.x + 6" :y="r.y + 16" fill="#fff" style="font-size: 9px; font-weight: 600">{{ r.label }}</text>
                    <text v-if="r.w > 44 && r.h > 34" :x="r.x + 6" :y="r.y + 28" fill="#fff" fill-opacity="0.8" style="font-size: 8px">{{ formatNumber(r.value) }}</text>
                </g>
            </svg>
        </template>

        <template v-else-if="block.chart_type === 'scatter' && scatter">
            <svg :viewBox="`0 0 ${scatter.w} ${scatter.h}`" class="w-full" :class="t.text">
                <line x1="18" :y1="scatter.h - 18" :x2="scatter.w - 8" :y2="scatter.h - 18" stroke="currentColor" stroke-opacity="0.15" />
                <line x1="18" y1="8" x2="18" :y2="scatter.h - 18" stroke="currentColor" stroke-opacity="0.15" />
                <circle
                    v-for="(p, i) in scatter.points" :key="i"
                    :cx="p.cx" :cy="p.cy" r="3.5" fill-opacity="0.7"
                    :style="{ fill: 'var(--sp-accent, #3B82F6)' }"
                />
            </svg>
        </template>

        <template v-else-if="isMultiSeries && multi">
            <!-- multi-series bar: stacked or grouped segments per category -->
            <ul class="mb-3 flex flex-wrap gap-x-3 gap-y-1 text-[11px]">
                <li v-for="(s, i) in multi.series" :key="i" class="flex items-center gap-1.5">
                    <span class="size-2.5 shrink-0 rounded-xs" :style="{ background: s.color }" />
                    <span class="truncate" :class="t.text">{{ s.label }}</span>
                </li>
            </ul>
            <div class="flex h-48 items-end gap-3 px-2 pt-2">
                <div
                    v-for="(cat, ci) in multi.cats"
                    :key="cat"
                    class="flex h-full min-w-0 flex-1 items-end justify-center"
                >
                    <!-- stacked: one column of stacked segments -->
                    <div v-if="multi.stacked" class="flex h-full w-full flex-col-reverse">
                        <div
                            v-for="(val, si) in multi.data[ci]"
                            :key="si"
                            class="w-full first:rounded-t-xs transition-all"
                            :style="{ height: (val / multi.max) * 100 + '%', background: multi.series[si].color }"
                            :title="multi.series[si].label + ': ' + formatNumber(val)"
                        />
                    </div>
                    <!-- grouped: thin bars side by side -->
                    <div v-else class="flex h-full w-full items-end justify-center gap-0.5">
                        <div
                            v-for="(val, si) in multi.data[ci]"
                            :key="si"
                            class="min-w-0 flex-1 rounded-t-xs transition-all"
                            :style="{ height: Math.max(2, (val / multi.max) * 100) + '%', background: multi.series[si].color }"
                            :title="multi.series[si].label + ': ' + formatNumber(val)"
                        />
                    </div>
                </div>
            </div>
            <div class="flex gap-3 px-2 pt-1">
                <div
                    v-for="cat in multi.cats"
                    :key="cat"
                    :class="['min-w-0 flex-1 truncate text-center text-[11px]', t.text]"
                    :title="cat"
                >
                    {{ cat }}
                </div>
            </div>
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
                    v-for="s in series"
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
