import type { FieldDef } from '../types/manifest';

export interface SparkRow {
    id: string;
    data: Record<string, unknown>;
}

export type SparkAggregation = 'count' | 'sum' | 'avg' | 'min' | 'max';

/** Truncate an ISO-ish datetime to YYYY-MM-DD. Returns null if unparseable. */
function toDayKey(raw: unknown): string | null {
    if (raw == null) return null;
    const s = String(raw);
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

function fold(vals: number[], agg: SparkAggregation): number {
    if (vals.length === 0) return 0;
    switch (agg) {
        case 'sum':
            return vals.reduce((a, b) => a + b, 0);
        case 'avg':
            return vals.reduce((a, b) => a + b, 0) / vals.length;
        case 'min':
            return Math.min(...vals);
        case 'max':
            return Math.max(...vals);
        default:
            return vals.length;
    }
}

/**
 * Bucket rows by the x field (or row order if none) and aggregate the y field
 * into a plain number[] trend. Date/datetime x fields are truncated to day and
 * gaps filled with 0 so "events over time" reads as a real trend instead of
 * disconnected buckets. Shared by the sparkline block and the inline KPI spark.
 */
export function computeSparkSeries(
    rows: SparkRow[],
    xField: FieldDef | undefined,
    yField: FieldDef | undefined,
    aggregation: SparkAggregation = 'count',
): number[] {
    const valueFor = (r: SparkRow): number => {
        if (!yField || aggregation === 'count') return 1;
        const v = Number(r.data[yField.slug] ?? 0);
        return Number.isFinite(v) ? v : 0;
    };

    if (!xField) {
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

        const span = daysBetween(minKey, maxKey);
        const out: number[] = [];
        for (let i = 0; i <= span; i++) {
            const vals = buckets.get(addDays(minKey, i));
            out.push(vals && vals.length ? fold(vals, aggregation) : 0);
        }
        return out;
    }

    const buckets = new Map<string, number[]>();
    for (const r of rows) {
        const key = String(r.data[xField.slug] ?? '');
        if (!buckets.has(key)) buckets.set(key, []);
        buckets.get(key)!.push(valueFor(r));
    }
    return Array.from(buckets.keys())
        .sort()
        .map((k) => fold(buckets.get(k)!, aggregation));
}

/**
 * Turn a series into SVG path strings for a `w`×`h` viewBox. The baseline is
 * anchored at 0 for a non-negative series (a flat row of 1s sits low, not
 * pinned to the top); an all-equal series is centred.
 */
export function buildSparkPath(
    series: number[],
    w: number,
    h: number,
): { line: string; area: string } {
    const n = series.length;
    if (n === 0) return { line: '', area: '' };
    const maxVal = Math.max(...series);
    const minVal = Math.min(...series);
    const lo = Math.min(0, minVal);
    const hi = Math.max(maxVal, lo + 1);
    const range = hi - lo;
    const points = series.map((v, i) => {
        const x = n === 1 ? w / 2 : (i / (n - 1)) * w;
        const y = range === 0 ? h / 2 : h - ((v - lo) / range) * h;
        return { sx: x.toFixed(1), sy: y.toFixed(1) };
    });
    const line = 'M ' + points.map((p) => `${p.sx},${p.sy}`).join(' L ');
    const first = points[0];
    const last = points[points.length - 1];
    const area = `${line} L ${last.sx},${h} L ${first.sx},${h} Z`;
    return { line, area };
}
