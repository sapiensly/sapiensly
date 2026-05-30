<script setup lang="ts">
import { computed } from 'vue';
import type { FieldDef, ObjectDef } from '../types/manifest';
import { resolveField } from '../types/manifest';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';

interface SparklineBlock {
    id: string;
    type: 'sparkline';
    label?: string;
    data_source: { object_id: string };
    x_field_id?: string;
    y_field_id?: string;
    aggregation?: 'count' | 'sum' | 'avg' | 'min' | 'max';
    color?: string;
}

interface RowData {
    id: string;
    data: Record<string, unknown>;
}

const props = defineProps<{
    block: SparklineBlock;
    data: { rows: RowData[] } | undefined;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

const t = themeTokens(useRuntimeTheme());

const object = computed<ObjectDef | undefined>(() =>
    props.objects.find((o) => o.id === props.block.data_source.object_id),
);

function fieldOf(id?: string): FieldDef | undefined {
    return resolveField(object.value, id);
}

/** Truncate an ISO-ish datetime to YYYY-MM-DD. Returns null if unparseable. */
function toDayKey(raw: unknown): string | null {
    if (raw == null) return null;
    const s = String(raw);
    // Fast path: already a date string.
    if (/^\d{4}-\d{2}-\d{2}/.test(s)) return s.slice(0, 10);
    const d = new Date(s);
    if (Number.isNaN(d.getTime())) return null;
    return d.toISOString().slice(0, 10);
}

/** Add `days` to a YYYY-MM-DD string and return the new YYYY-MM-DD. */
function addDays(dayKey: string, days: number): string {
    const d = new Date(dayKey + 'T00:00:00Z');
    d.setUTCDate(d.getUTCDate() + days);
    return d.toISOString().slice(0, 10);
}

/** Inclusive day-count between two YYYY-MM-DD keys. */
function daysBetween(a: string, b: string): number {
    const da = new Date(a + 'T00:00:00Z').getTime();
    const db = new Date(b + 'T00:00:00Z').getTime();
    return Math.round((db - da) / 86400000);
}

// Bucket rows by the x field (or row index if no x given) and aggregate the
// y field. For date/datetime x fields we truncate to day and fill gaps with 0
// so a sparkline of "events over time" reads as a real trend instead of N
// disconnected nanosecond-buckets.
const series = computed<number[]>(() => {
    const rows = props.data?.rows ?? [];
    const xField = fieldOf(props.block.x_field_id);
    const yField = fieldOf(props.block.y_field_id);
    const agg = props.block.aggregation ?? 'count';

    const valueFor = (r: RowData): number => {
        if (!yField || agg === 'count') return 1;
        const v = Number(r.data[yField.slug] ?? 0);
        return Number.isFinite(v) ? v : 0;
    };

    if (!xField) {
        // No grouping — every row counts as one bucket.
        return rows.map(valueFor);
    }

    const isTemporal = xField.type === 'date' || xField.type === 'datetime';

    if (isTemporal) {
        const buckets = new Map<string, number[]>();
        let minKey: string | null = null;
        let maxKey: string | null = null;
        for (const r of rows) {
            const key = toDayKey(r.data[xField.slug]);
            if (!key) continue;
            if (!buckets.has(key)) buckets.set(key, []);
            buckets.get(key)!.push(valueFor(r));
            if (minKey === null || key < minKey) minKey = key;
            if (maxKey === null || key > maxKey) maxKey = key;
        }
        if (minKey === null || maxKey === null) return [];

        // Walk every day from min to max so gaps render as zeros.
        const span = daysBetween(minKey, maxKey);
        const out: number[] = [];
        for (let i = 0; i <= span; i++) {
            const key = addDays(minKey, i);
            const vals = buckets.get(key);
            if (!vals || vals.length === 0) {
                out.push(0);
                continue;
            }
            switch (agg) {
                case 'sum': out.push(vals.reduce((a, b) => a + b, 0)); break;
                case 'avg': out.push(vals.reduce((a, b) => a + b, 0) / vals.length); break;
                case 'min': out.push(Math.min(...vals)); break;
                case 'max': out.push(Math.max(...vals)); break;
                default: out.push(vals.length);
            }
        }
        return out;
    }

    // Non-temporal grouping — bucket by literal value, sort lexicographically.
    const buckets = new Map<string, number[]>();
    for (const r of rows) {
        const key = String(r.data[xField.slug] ?? '');
        if (!buckets.has(key)) buckets.set(key, []);
        buckets.get(key)!.push(valueFor(r));
    }
    const keys = Array.from(buckets.keys()).sort();
    return keys.map((k) => {
        const vals = buckets.get(k)!;
        switch (agg) {
            case 'sum': return vals.reduce((a, b) => a + b, 0);
            case 'avg': return vals.length ? vals.reduce((a, b) => a + b, 0) / vals.length : 0;
            case 'min': return vals.length ? Math.min(...vals) : 0;
            case 'max': return vals.length ? Math.max(...vals) : 0;
            default: return vals.length;
        }
    });
});

const path = computed(() => {
    const w = 240;
    const h = 48;
    const n = series.value.length;
    if (n === 0) return { line: '', area: '', last: 0, total: 0 };
    const maxVal = Math.max(...series.value);
    const minVal = Math.min(...series.value);
    // Anchor the baseline at 0 when the series is non-negative, so a flat row
    // of 1s shows as a low line instead of pinned to the top. When all values
    // are equal we centre vertically.
    const lo = Math.min(0, minVal);
    const hi = Math.max(maxVal, lo + 1);
    const range = hi - lo;
    const points = series.value.map((v, i) => {
        const x = n === 1 ? w / 2 : (i / (n - 1)) * w;
        const y = range === 0 ? h / 2 : h - ((v - lo) / range) * h;
        return { x, y, sx: x.toFixed(1), sy: y.toFixed(1) };
    });
    const line = 'M ' + points.map((p) => `${p.sx},${p.sy}`).join(' L ');
    // Soft fill under the line to give it body.
    const first = points[0];
    const last = points[points.length - 1];
    const area = `${line} L ${last.sx},${h} L ${first.sx},${h} Z`;
    return {
        line,
        area,
        last: series.value[series.value.length - 1],
        total: series.value.reduce((a, b) => a + b, 0),
    };
});

const color = computed(() => props.block.color ?? '#3B82F6');

function formatNumber(value: number): string {
    return new Intl.NumberFormat(props.locale).format(Math.round(value * 100) / 100);
}
</script>

<template>
    <div :class="['rounded-sp-sm border p-4', t.surface]">
        <header v-if="block.label" class="mb-2 flex items-center justify-between">
            <p :class="['text-[11px] uppercase tracking-wider', t.textSubtle]">{{ block.label }}</p>
            <p :class="['text-sm font-semibold', t.text]">{{ formatNumber(path.total) }}</p>
        </header>
        <svg
            v-if="series.length > 0"
            viewBox="0 0 240 48"
            class="w-full"
            preserveAspectRatio="none"
        >
            <path :d="path.area" :fill="color" fill-opacity="0.12" stroke="none" />
            <path :d="path.line" fill="none" :stroke="color" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        <p v-else :class="['py-4 text-center text-xs', t.textMuted]">No data.</p>
    </div>
</template>
