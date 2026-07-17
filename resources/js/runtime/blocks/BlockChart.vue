<script setup lang="ts">
import HBarChart from '@/components/charts/HBarChart.vue';
import ParetoChart from '@/components/charts/ParetoChart.vue';
import TreemapChart from '@/components/charts/TreemapChart.vue';
import { useElementSize } from '@/composables/useElementSize';
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type { FieldDef, ObjectDef } from '../types/manifest';
import { resolveField } from '../types/manifest';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';

type Agg = 'count' | 'sum' | 'avg' | 'min' | 'max';

interface ComboSeries {
    type: 'bar' | 'line' | 'area';
    aggregation: Agg;
    field_id?: string;
    label?: string;
    axis?: 'left' | 'right';
    color?: string;
}

interface ChartBlock {
    id: string;
    type: 'chart';
    label?: string;
    description?: string;
    drill_param?: string;
    chart_type:
        | 'bar'
        | 'hbar'
        | 'line'
        | 'area'
        | 'pie'
        | 'donut'
        | 'radar'
        | 'scatter'
        | 'treemap'
        | 'sankey'
        | 'box'
        | 'pareto';
    data_source: { object_id: string };
    x_field_id?: string;
    y_field_id?: string;
    group_by_field_id?: string;
    series_field_id?: string;
    stacked?: boolean;
    aggregation: Agg;
    bucket?: 'day' | 'week' | 'month' | 'quarter' | 'year';
    series?: ComboSeries[];
}

interface RowData {
    id: string;
    data: Record<string, unknown>;
}

const props = defineProps<{
    block: ChartBlock;
    // A breakdown arrives already GROUPED — aggregated where the data lives, over
    // every matching record, not over the row window this chart happened to
    // fetch. `groups` carries `group2` too when a second categorical makes it a
    // pivot (stacked, radar, sankey); `combo` carries one grouped series per
    // overlaid measure. Only the row-level forms — scatter, box — still receive
    // rows, because they plot records rather than categories.
    data:
        | {
              rows?: RowData[];
              groups?: { group: unknown; group2?: unknown; value: number }[];
              combo?: {
                  groups: { group: unknown; value: number }[];
              }[];
          }
        | undefined;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

const t = themeTokens(useRuntimeTheme());

// Drill-down: clicking a category toggles the page param the category select
// filter owns — the whole board (KPIs, charts, detail table) re-scopes
// through wiring that already exists; clicking the active value clears it.
function onDrill(label: string) {
    const param = props.block.drill_param;
    if (!param) return;
    const query: Record<string, string> = {};
    new URLSearchParams(window.location.search).forEach((v, k) => {
        query[k] = v;
    });
    if (query[param] === label) {
        delete query[param];
    } else {
        query[param] = label;
    }
    router.get(window.location.pathname, query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

// A short date-range preset (Hoy / 7 días) buckets by DAY regardless of the
// chart's authored grain — a weekly bucket over 7 days is one bar. The reader
// already asks the source for daily rows on a short window; this displays them
// daily. Reactive to the active range (usePage updates on the filter's visit),
// falling back to the chart's own bucket for 30d+ or a non-runtime context.
const page = usePage();
const activeRange = computed<string>(() => {
    const params = page.props?.params as Record<string, unknown> | undefined;
    return typeof params?.range === 'string' ? params.range : '';
});
const effectiveBucket = computed<ChartBlock['bucket']>(() =>
    activeRange.value === 'today' || activeRange.value === '7d'
        ? 'day'
        : props.block.bucket,
);

// Shared hover tooltip for EVERY chart type: each mark toggles `tip` content on
// mouseenter/leave while the card tracks the cursor, so one floating tooltip
// follows the pointer across SVG marks and HTML bars alike. One mechanism, so
// every dashboard chart gets consistent hover info without per-chart wiring.
const card = ref<HTMLElement | null>(null);
// The SVG plot host — measured so the axis charts' viewBox can match the
// card's real aspect ratio and FILL it (re-laid-out at the new scale) instead
// of letterboxing. Only one chart branch renders, so a single ref suffices.
const chartHostEl = ref<HTMLElement | null>(null);
const { width: hostW, height: hostH } = useElementSize(chartHostEl);
/** viewBox height that matches the host aspect (keeping viewBox width `vw`),
 *  clamped to a sane band; falls back to `def` off an explicit height. */
function fitVH(vw: number, def: number, lo: number, hi: number): number {
    if (!hasExplicitHeight.value || hostW.value === 0 || hostH.value === 0) {
        return def;
    }
    return Math.round(
        Math.min(hi, Math.max(lo, (vw * hostH.value) / hostW.value)),
    );
}
const mouse = ref({ x: 0, y: 0 });
const tip = ref<{ title: string; value?: string; color?: string } | null>(null);

function onMove(e: MouseEvent): void {
    const r = card.value?.getBoundingClientRect();
    if (r) {
        mouse.value = { x: e.clientX - r.left, y: e.clientY - r.top };
    }
}
function showTip(title: string, value?: string, color?: string): void {
    tip.value = { title, value, color };
}
function hideTip(): void {
    tip.value = null;
    activeIdx.value = null;
}

const object = computed<ObjectDef | undefined>(() =>
    props.objects.find((o) => o.id === props.block.data_source.object_id),
);

// Manual adjust wrote an explicit card height: plot areas drop their
// intrinsic minimums and track the card instead.
const hasExplicitHeight = computed(
    () =>
        !!(props.block.style as { min_height?: number } | undefined)
            ?.min_height,
);

function fieldOf(id: string | undefined): FieldDef | undefined {
    return resolveField(object.value, id);
}

const groupField = computed(() =>
    fieldOf(props.block.group_by_field_id ?? props.block.x_field_id),
);
const yField = computed(() => fieldOf(props.block.y_field_id));

// A string column that is really the series' bucket label — mirror of the
// backend suggester's bucketLabelField() heuristic.
const PERIOD_LABEL_SLUG = /label|bucket|period|semana|week/i;

// The series a breakdown draws. The server groups wherever it can — over every
// record, not over the row window this chart happened to fetch — and only the
// forms it can't group still fold their rows here.
const series = computed<{ label: string; value: number }[]>(() => {
    const groupSlug = groupField.value?.slug;
    const out = props.data?.groups
        ? props.data.groups.map((g) => ({
              label: formatGroupKey(g.group, groupField.value),
              value: Number(g.value) || 0,
          }))
        : foldRows();

    return sortSeries(out, groupSlug);
});

/** Fold raw rows client-side — the fallback for the forms the server can't group. */
function foldRows(): { label: string; value: number }[] {
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
                value =
                    values.length === 0
                        ? 0
                        : values.reduce((a, b) => a + b, 0) / values.length;
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
}

/**
 * A server pivot ({group, group2, value}) as the category × series grid the
 * multi-series forms fold rows into — except every cell is already aggregated,
 * over every matching record rather than over a row window.
 */
function pivotGrid(
    groups: { group: unknown; group2?: unknown; value: number }[],
    catField: FieldDef | undefined,
    serField: FieldDef | undefined,
): { cats: string[]; sers: string[]; data: number[][] } {
    const cats: string[] = [];
    const catIndex = new Map<string, number>();
    const sers: string[] = [];
    const serIndex = new Map<string, number>();
    const data: number[][] = [];

    for (const g of groups) {
        const ck = formatGroupKey(g.group, catField);
        const sk = serField ? formatGroupKey(g.group2, serField) : '__single__';
        let ci = catIndex.get(ck);
        if (ci === undefined) {
            ci = cats.length;
            cats.push(ck);
            catIndex.set(ck, ci);
        }
        let si = serIndex.get(sk);
        if (si === undefined) {
            si = sers.length;
            sers.push(sk);
            serIndex.set(sk, si);
        }
        (data[ci] ??= [])[si] = Number(g.value) || 0;
    }
    // A pivot is sparse — a category the series never touched is a real zero.
    cats.forEach((_, ci) => {
        data[ci] ??= [];
        sers.forEach((_, si) => {
            data[ci][si] ??= 0;
        });
    });

    return { cats, sers, data };
}

/** The reading order of a series, whoever aggregated it. */
function sortSeries(
    out: { label: string; value: number }[],
    groupSlug: string | undefined,
): { label: string; value: number }[] {
    // A bucketed date X reads chronologically; bucket keys sort lexicographically
    // in time order (YYYY-MM-DD, YYYY-MM, YYYY-Qn, YYYY).
    if (isTemporal(groupField.value)) {
        out.sort((a, b) =>
            a.label < b.label ? -1 : a.label > b.label ? 1 : 0,
        );
    } else if (
        (props.block.chart_type === 'bar' ||
            props.block.chart_type === 'hbar' ||
            props.block.chart_type === 'pareto') &&
        groupSlug &&
        !PERIOD_LABEL_SLUG.test(groupSlug)
    ) {
        // Categorical breakdowns read Pareto: biggest first (same order donut
        // and treemap already use). Period-label categories (Semana 1…) are
        // the time axis in a string costume and keep their chronological
        // insertion order instead.
        out.sort((a, b) => b.value - a.value);
    }

    return out;
}

function isTemporal(field: FieldDef | undefined): boolean {
    return field?.type === 'date' || field?.type === 'datetime';
}

/**
 * Truncate a date/datetime value to the chart's bucket, matching the backend's
 * date_trunc buckets so a chart shows one point per period (chronologically
 * sortable as a string) instead of one per raw timestamp. Falls back to the raw
 * string when unparseable.
 */
function bucketDate(value: unknown, bucket: ChartBlock['bucket']): string {
    const s = String(value);
    const d = new Date(s);
    if (Number.isNaN(d.getTime())) return s;
    const y = d.getUTCFullYear();
    const mm = String(d.getUTCMonth() + 1).padStart(2, '0');
    const dd = String(d.getUTCDate()).padStart(2, '0');
    switch (bucket) {
        case 'year':
            return `${y}`;
        case 'quarter':
            return `${y}-Q${Math.floor(d.getUTCMonth() / 3) + 1}`;
        case 'month':
            return `${y}-${mm}`;
        case 'week': {
            // Monday of the ISO week, as a sortable YYYY-MM-DD.
            const w = new Date(Date.UTC(y, d.getUTCMonth(), d.getUTCDate()));
            const wd = (w.getUTCDay() + 6) % 7;
            w.setUTCDate(w.getUTCDate() - wd);
            return w.toISOString().slice(0, 10);
        }
        default:
            return `${y}-${mm}-${dd}`; // day
    }
}

function formatGroupKey(value: unknown, field: FieldDef | undefined): string {
    if (value === null || value === undefined || value === '') return '—';
    if (field?.type === 'single_select') {
        const opt = field.options?.find((o) => o.value === value);
        return opt?.label ?? String(value);
    }
    // Date/datetime X (incl. sys_created_at) → truncate to the bucket (default day).
    if (isTemporal(field)) {
        return bucketDate(value, effectiveBucket.value ?? 'day');
    }
    return String(value);
}

// Brand-derived categorical series (the surface sets --sp-chart-1…6 from the
// org/app accent); falls back to a neutral professional palette when absent.
const CHART_FALLBACK = [
    '#3B82F6',
    '#10B981',
    '#F59E0B',
    '#EC4899',
    '#8B5CF6',
    '#06B6D4',
];
function chartColor(index: number): string {
    return `var(--sp-chart-${(index % 6) + 1}, ${CHART_FALLBACK[index % CHART_FALLBACK.length]})`;
}

function colorFor(label: string, index: number): string {
    if (groupField.value?.type === 'single_select') {
        const opt = groupField.value.options?.find(
            (o) => o.label === label || o.value === label,
        );
        if (opt?.color) return opt.color;
    }
    return chartColor(index);
}

const maxValue = computed(() =>
    Math.max(1, ...series.value.map((s) => s.value)),
);
const totalValue = computed(() =>
    series.value.reduce((a, s) => a + s.value, 0),
);

// Unique per-block id so multiple charts' gradient defs never collide
// (block ids are minted by the compiler, so this is stable per chart).
const gradientId = computed(() => `sp-area-${props.block.id ?? 'chart'}`);

// Caption under the donut's center total: what the slices sum TO (the measure
// name), else a neutral "total". Count charts read as records.
const donutCenterLabel = computed(() => {
    if (props.block.aggregation === 'count') {
        return 'total';
    }
    return yField.value?.name ?? 'total';
});

// Pick a colour for a value of an arbitrary field (honours single_select option
// colours), used for the series legend in stacked/grouped charts.
function paletteColorFor(
    field: FieldDef | undefined,
    label: string,
    index: number,
): string {
    if (field?.type === 'single_select') {
        const opt = field.options?.find(
            (o) => o.label === label || o.value === label,
        );
        if (opt?.color) return opt.color;
    }
    return chartColor(index);
}

const seriesField = computed(() => fieldOf(props.block.series_field_id));

// Multi-series bar: split each category (group_by/x) into segments by a SECOND
// field (series_field_id), then stack or group them — a two-dimensional
// breakdown, which the server resolves as a pivot.
const isMultiSeries = computed(
    () => !!seriesField.value && props.block.chart_type === 'bar',
);

const multi = computed(() => {
    if (!isMultiSeries.value) return null;
    const catField = groupField.value;
    const serField = seriesField.value;
    if (!catField || !serField) return null;

    const grid = props.data?.groups
        ? pivotGrid(props.data.groups, catField, serField)
        : foldPivotRows(catField, serField);
    if (grid === null) return null;

    const cats = grid.cats;
    const sers = grid.sers;
    let data = grid.data;

    // Chronological order when the category axis is a bucketed date.
    if (isTemporal(catField)) {
        const order = cats
            .map((_, i) => i)
            .sort((a, b) =>
                cats[a] < cats[b] ? -1 : cats[a] > cats[b] ? 1 : 0,
            );
        const sortedCats = order.map((i) => cats[i]);
        cats.length = 0;
        cats.push(...sortedCats);
        data = order.map((i) => data[i]);
    }
    const stacked = !!props.block.stacked;
    const max = stacked
        ? Math.max(1, ...data.map((row) => row.reduce((a, b) => a + b, 0)))
        : Math.max(1, ...data.flat());

    const series = sers.map((label, i) => ({
        label,
        color: paletteColorFor(serField, label, i),
    }));

    return { cats, series, data, max, stacked };
});

/** The client-side pivot fold, for a source the server could not group. */
function foldPivotRows(
    catField: FieldDef,
    serField: FieldDef,
): { cats: string[]; sers: string[]; data: number[][] } | null {
    const rows = props.data?.rows ?? [];
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
        if (ci === undefined) {
            ci = cats.length;
            cats.push(ck);
            catIndex.set(ck, ci);
        }
        let si = serIndex.get(sk);
        if (si === undefined) {
            si = sers.length;
            sers.push(sk);
            serIndex.set(sk, si);
        }
        (bucket[ci] ??= [])[si] ??= [];
        const yRaw = ySlug ? Number(r.data[ySlug] ?? 0) : 1;
        bucket[ci][si].push(Number.isFinite(yRaw) ? yRaw : 0);
    }

    const aggregate = (vals: number[] | undefined): number => {
        if (!vals || vals.length === 0) return 0;
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
                return vals.length; // count
        }
    };

    const data = cats.map((_, ci) =>
        sers.map((_, si) => aggregate(bucket[ci]?.[si])),
    );

    return { cats, sers, data };
}

function formatNumber(value: number): string {
    return new Intl.NumberFormat(props.locale).format(
        Math.round(value * 100) / 100,
    );
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

// Line / area chart, one OR several series. When series_field_id is set each of
// its values becomes its own line over the shared (bucketed) X — so e.g. "created
// vs closed per week" renders as two brand-coloured lines, not one collapsed one.
// Geometry is bounded (a wide viewBox scaled to fit a fixed-height box) so a line
// chart never balloons, and colours come from the palette / option colours.
// Smooth a polyline into a Catmull-Rom → cubic-Bézier path so line/area charts
// read as gentle curves instead of hard elbows. Tension is kept low so the
// curve hugs the points without big overshoots. Falls back to straight segments
// for < 3 points (nothing to smooth).
// A "nice" round number at or above v — 1/2/5 × a power of ten — so a Y axis
// lands on readable gridlines (0, 50, 100, 150) instead of raw maxima.
function niceCeil(v: number): number {
    if (v <= 0) return 1;
    const mag = Math.pow(10, Math.floor(Math.log10(v)));
    const norm = v / mag;
    const step = norm <= 1 ? 1 : norm <= 2 ? 2 : norm <= 5 ? 5 : 10;
    return step * mag;
}

/**
 * A Y axis from ZERO up to a nice round top, divided into round steps (~4-5
 * gridlines). Mirrors the design system's time-series axis: discrete, legible,
 * anchored at zero so magnitudes read honestly.
 */
function niceAxis(max: number): { top: number; ticks: number[] } {
    const step = niceCeil(Math.max(1, max) / 4);
    const top = Math.ceil(Math.max(1, max) / step) * step;
    const ticks: number[] = [];
    for (let v = 0; v <= top + step / 2; v += step) ticks.push(v);
    return { top, ticks };
}

function smoothLine(pts: { x: number; y: number }[]): string {
    if (pts.length === 0) return '';
    if (pts.length < 3) {
        return pts
            .map((p, i) => (i === 0 ? `M ${p.x} ${p.y}` : `L ${p.x} ${p.y}`))
            .join(' ');
    }
    const tension = 0.16;
    let d = `M ${pts[0].x} ${pts[0].y}`;
    for (let i = 0; i < pts.length - 1; i++) {
        const p0 = pts[i - 1] ?? pts[i];
        const p1 = pts[i];
        const p2 = pts[i + 1];
        const p3 = pts[i + 2] ?? p2;
        const c1x = p1.x + (p2.x - p0.x) * tension;
        const c1y = p1.y + (p2.y - p0.y) * tension;
        const c2x = p2.x - (p3.x - p1.x) * tension;
        const c2y = p2.y - (p3.y - p1.y) * tension;
        d += ` C ${c1x} ${c1y} ${c2x} ${c2y} ${p2.x} ${p2.y}`;
    }
    return d;
}

// The category index the cursor is over on a line/area chart — drives the
// vertical crosshair, the highlighted markers and the multi-series tooltip.
const activeIdx = ref<number | null>(null);

// ---- categorical x-axis fitting -------------------------------------------
// Long category labels (ticket reasons, product names…) cannot render
// horizontally under their bars — a dozen sentence-long strings overlap into
// noise. When the longest label outgrows its slot the axis switches to
// truncated diagonals (the full text stays on the hover tooltip), pays the
// extra bottom padding, and thins ticks if even diagonals would collide.
const AXIS_CHAR_W = 4.6; // ≈ glyph width of the 8px tick font
const AXIS_MAX_CHARS = 18;
const AXIS_DIAG = AXIS_CHAR_W * 0.707; // per-char x AND y extent at -45°

function catAxisLayout(labels: string[], slot: number, leftRoom: number) {
    const maxLen = Math.max(0, ...labels.map((l) => l.length));
    if (maxLen * AXIS_CHAR_W <= slot + 2) {
        return { rotated: false, padB: 30, stride: 1, trim: (s: string) => s };
    }
    // The first diagonal grows up-left from its anchor; the svg clips outside
    // its viewBox, so the char budget is also bounded by the room to the left.
    const chars = Math.max(
        6,
        Math.min(maxLen, AXIS_MAX_CHARS, Math.floor(leftRoom / AXIS_DIAG)),
    );
    return {
        rotated: true,
        padB: 16 + Math.ceil(chars * AXIS_DIAG),
        stride: Math.max(1, Math.ceil(9 / slot)),
        trim: (s: string) =>
            s.length > chars ? s.slice(0, chars - 1).trimEnd() + '…' : s,
    };
}

const lineChart = computed(() => {
    const isLine =
        props.block.chart_type === 'line' || props.block.chart_type === 'area';
    if (!isLine) return null;

    const xField = groupField.value;
    const serField = seriesField.value;

    let orderedCats: string[];
    let serLabels: string[];
    let values: number[][];

    if (props.data?.groups && serField) {
        // Several lines over one X: a pivot, already aggregated per cell.
        const grid = pivotGrid(props.data.groups, xField, serField);
        if (grid.cats.length === 0) return null;
        let order = grid.cats.map((_, i) => i);
        if (isTemporal(xField)) {
            order = order.sort((a, b) =>
                grid.cats[a] < grid.cats[b]
                    ? -1
                    : grid.cats[a] > grid.cats[b]
                      ? 1
                      : 0,
            );
        }
        orderedCats = order.map((i) => grid.cats[i]);
        serLabels = grid.sers;
        values = grid.sers.map((_, si) => order.map((ci) => grid.data[ci][si]));
    } else if (props.data?.groups) {
        // Already aggregated where the data lives, over every matching record —
        // and already in reading order.
        const points = series.value;
        if (points.length === 0) return null;
        orderedCats = points.map((p) => p.label);
        serLabels = ['__single__'];
        values = [points.map((p) => p.value)];
    } else {
        const rows = props.data?.rows ?? [];
        const xSlug = xField?.slug;
        const ySlug = yField.value?.slug;
        const agg = props.block.aggregation;
        const serSlug = serField?.slug;

        const cats: string[] = [];
        const catIndex = new Map<string, number>();
        const labels: string[] = [];
        const serIndex = new Map<string, number>();
        const cells: number[][][] = []; // [seriesIdx][catIdx] = raw values

        for (const r of rows) {
            const ck = formatGroupKey(xSlug ? r.data[xSlug] : 'all', xField);
            let ci = catIndex.get(ck);
            if (ci === undefined) {
                ci = cats.length;
                cats.push(ck);
                catIndex.set(ck, ci);
            }
            const sk = serField
                ? formatGroupKey(serSlug ? r.data[serSlug] : '—', serField)
                : '__single__';
            let si = serIndex.get(sk);
            if (si === undefined) {
                si = labels.length;
                labels.push(sk);
                serIndex.set(sk, si);
            }
            const yv = ySlug ? Number(r.data[ySlug] ?? 0) : 1;
            ((cells[si] ??= [])[ci] ??= []).push(Number.isFinite(yv) ? yv : 0);
        }
        if (cats.length === 0) return null;

        // Chronological X for a bucketed date; keys sort in time order as strings.
        let order = cats.map((_, i) => i);
        if (isTemporal(xField)) {
            order = order.sort((a, b) =>
                cats[a] < cats[b] ? -1 : cats[a] > cats[b] ? 1 : 0,
            );
        }
        orderedCats = order.map((i) => cats[i]);
        serLabels = labels;
        values = labels.map((_, si) =>
            order.map((ci) => aggregateVals(cells[si]?.[ci], agg)),
        );
    }

    const max = Math.max(1, ...values.flat());
    // A zero-anchored axis rounded to a nice top, so gridlines read as round
    // numbers and every series shares one honest scale.
    const axis = niceAxis(max);
    const top = axis.top;

    const W = 520;
    const H = fitVH(W, 200, 150, 460);
    const padL = 38;
    const padR = 12;
    const padT = 12;
    const padB = 26;
    const innerW = W - padL - padR;
    const innerH = H - padT - padB;
    const n = orderedCats.length;
    const stepX = n <= 1 ? 0 : innerW / (n - 1);
    const xAt = (i: number) => padL + (n === 1 ? innerW / 2 : i * stepX);
    const yAt = (v: number) => padT + innerH - (v / top) * innerH;
    const single = !serField;

    const seriesOut = serLabels.map((label, si) => {
        const points = orderedCats.map((c, i) => ({
            x: xAt(i),
            y: yAt(values[si][i]),
            label: c,
            value: values[si][i],
        }));
        const line = smoothLine(points);
        const area =
            points.length && line
                ? `${line} L ${points[points.length - 1].x} ${padT + innerH} L ${points[0].x} ${padT + innerH} Z`
                : '';
        return {
            label: single ? (yField.value?.name ?? '') : label,
            color: serField
                ? paletteColorFor(serField, label, si)
                : chartColor(0),
            line,
            area,
            points,
        };
    });

    // Round gridlines from zero to the nice top — discrete, legible axis.
    const yTicks = axis.ticks.map((v) => ({
        y: yAt(v),
        label: formatNumber(v),
    }));
    // A dashed mean reference for a SINGLE series — "where the period sits".
    const flatVals = values[0] ?? [];
    const avg =
        single && flatVals.length
            ? flatVals.reduce((a, b) => a + b, 0) / flatVals.length
            : null;
    const avgY = avg !== null ? yAt(avg) : null;
    // Thin the X labels so long date ticks never collide (~6 across).
    const stride = Math.max(1, Math.ceil(n / 6));
    const xLabels = orderedCats
        .map((label, i) => ({ x: xAt(i), label, i }))
        .filter((l) => l.i % stride === 0 || l.i === n - 1);

    return {
        W,
        H,
        padL,
        padR,
        top: padT,
        baselineY: padT + innerH,
        stepX,
        xs: orderedCats.map((_, i) => xAt(i)),
        cats: orderedCats,
        single,
        series: seriesOut,
        yTicks,
        xLabels,
        avgY,
        avgLabel: avg !== null ? formatNumber(avg) : '',
    };
});

// The active crosshair: the hovered category's x, its label, and every series'
// point/value there — for the vertical guide, markers and tooltip rows.
const crosshair = computed(() => {
    const lc = lineChart.value;
    const i = activeIdx.value;
    if (!lc || i === null || i < 0 || i >= lc.cats.length) return null;
    return {
        x: lc.xs[i],
        label: lc.cats[i],
        rows: lc.series.map((s) => ({
            label: s.label || props.block.label || 'Valor',
            color: s.color,
            y: s.points[i]?.y ?? 0,
            value: formatNumber(s.points[i]?.value ?? 0),
        })),
    };
});

// Radar: plot the categorical buckets on radial axes (needs >= 3 axes).
// Radar: group_by_field_id categories become the radial AXES; each value is a
// point on its axis. With series_field_id set, every one of its values overlays
// its OWN polygon on the same axes (compare 2-3 entities across the dimensions);
// without it, a single polygon. Needs >= 3 axes to form an area.
const radar = computed(() => {
    if (props.block.chart_type !== 'radar') return null;
    const axisField = groupField.value;
    const serField = seriesField.value;

    let axesLabels: string[];
    let serLabels: string[];
    let values: number[][];

    if (props.data?.groups) {
        // Axes × overlaid polygons is a pivot, already aggregated per cell.
        const grid = pivotGrid(props.data.groups, axisField, serField);
        axesLabels = grid.cats;
        serLabels = grid.sers;
        values = grid.sers.map((_, si) =>
            grid.cats.map((_, ai) => grid.data[ai][si]),
        );
    } else {
        const rows = props.data?.rows ?? [];
        const axisSlug = axisField?.slug;
        const serSlug = serField?.slug;
        const ySlug = yField.value?.slug;
        const agg = props.block.aggregation;

        axesLabels = [];
        serLabels = [];
        const axisIndex = new Map<string, number>();
        const serIndex = new Map<string, number>();
        const cells: number[][][] = []; // [seriesIdx][axisIdx] = raw values

        for (const r of rows) {
            const ak = formatGroupKey(
                axisSlug ? r.data[axisSlug] : 'all',
                axisField,
            );
            let ai = axisIndex.get(ak);
            if (ai === undefined) {
                ai = axesLabels.length;
                axesLabels.push(ak);
                axisIndex.set(ak, ai);
            }
            const sk = serField
                ? formatGroupKey(serSlug ? r.data[serSlug] : '—', serField)
                : '__single__';
            let si = serIndex.get(sk);
            if (si === undefined) {
                si = serLabels.length;
                serLabels.push(sk);
                serIndex.set(sk, si);
            }
            const v = ySlug ? Number(r.data[ySlug] ?? 0) : 1;
            ((cells[si] ??= [])[ai] ??= []).push(Number.isFinite(v) ? v : 0);
        }

        values = serLabels.map((_, si) =>
            axesLabels.map((_, ai) => aggregateVals(cells[si]?.[ai], agg)),
        );
    }

    const n = axesLabels.length;
    if (n < 3) return null;
    const max = Math.max(1, ...values.flat());

    const cx = 110;
    const cy = 110;
    const r = 84;
    const angleAt = (i: number) => -Math.PI / 2 + (i / n) * Math.PI * 2;
    const axes = axesLabels.map((label, i) => {
        const ang = angleAt(i);
        return {
            label,
            ax: cx + r * Math.cos(ang),
            ay: cy + r * Math.sin(ang),
            lx: cx + (r + 16) * Math.cos(ang),
            ly: cy + (r + 16) * Math.sin(ang),
        };
    });

    const single = !serField;
    const polys = serLabels.map((label, si) => {
        const points = axesLabels.map((axisLabel, ai) => {
            const ang = angleAt(ai);
            const rr = (values[si][ai] / max) * r;
            return {
                x: cx + rr * Math.cos(ang),
                y: cy + rr * Math.sin(ang),
                value: values[si][ai],
                axis: axisLabel,
            };
        });
        const d =
            points
                .map(
                    (p, k) =>
                        `${k === 0 ? 'M' : 'L'} ${p.x.toFixed(1)} ${p.y.toFixed(1)}`,
                )
                .join(' ') + ' Z';
        return {
            label: single ? '' : label,
            color: serField
                ? paletteColorFor(serField, label, si)
                : 'var(--sp-accent, #3B82F6)',
            d,
            points,
        };
    });

    return { cx, cy, r, axes, polys, single };
});

// Treemap: squarified rectangles sized by each bucket's value (segmentation).

// Combo: overlay several typed measures (bar/line/area) on a shared X axis,
// each scaled against the left (primary) or right (secondary) Y axis. When
// block.series is set, this replaces the single-type render above. All geometry
// is precomputed here so the template just paints.
const isCombo = computed(
    () => Array.isArray(props.block.series) && props.block.series.length > 0,
);

function aggregateVals(vals: number[] | undefined, agg: Agg): number {
    if (!vals || vals.length === 0) return 0;
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
            return vals.length; // count
    }
}

// Pareto renders through the dedicated, reusable component: vital-few
// highlighting, threshold line, insight badge — series.value already sorts
// categorical breakdowns descending.
const paretoItems = computed(() =>
    props.block.chart_type === 'pareto'
        ? series.value.filter((d) => d.value > 0)
        : [],
);
const paretoMeasureLabel = computed(
    () =>
        yField.value?.name ??
        (props.block.aggregation === 'count' ? 'Total' : 'Valor'),
);
const paretoNoun = computed(() => {
    const name = groupField.value?.name ?? '';
    return name !== '' ? name.toLowerCase() : 'categorías';
});

const combo = computed(() => {
    if (!isCombo.value) return null;
    const defs = props.block.series!;
    const xField = groupField.value; // group_by_field_id ?? x_field_id

    const cats: string[] = [];
    const catIndex = new Map<string, number>();
    let vals: number[][];

    const served = props.data?.combo;
    if (served) {
        // One grouped aggregate per overlaid measure — each keeping its OWN
        // aggregation (volume summed, rate averaged), which is why they can't
        // share a single fold. The categories are the union across series, so a
        // gap in one line is a real zero, not a missing column.
        served.forEach((s) => {
            s.groups.forEach((g) => {
                const key = formatGroupKey(g.group, xField);
                if (!catIndex.has(key)) {
                    catIndex.set(key, cats.length);
                    cats.push(key);
                }
            });
        });
        if (cats.length === 0) return null;
        vals = served.map((s) => {
            const row = new Array<number>(cats.length).fill(0);
            s.groups.forEach((g) => {
                const ci = catIndex.get(formatGroupKey(g.group, xField));
                if (ci !== undefined) {
                    row[ci] = Number(g.value) || 0;
                }
            });

            return row;
        });
    } else {
        const rows = props.data?.rows ?? [];
        const xSlug = xField?.slug;
        const raw: number[][][] = defs.map(() => []);
        for (const r of rows) {
            const key = formatGroupKey(xSlug ? r.data[xSlug] : 'all', xField);
            let ci = catIndex.get(key);
            if (ci === undefined) {
                ci = cats.length;
                cats.push(key);
                catIndex.set(key, ci);
            }
            defs.forEach((d, si) => {
                const fld = fieldOf(d.field_id);
                const v = fld ? Number(r.data[fld.slug] ?? 0) : 1; // count → 1 per row
                (raw[si][ci] ??= []).push(Number.isFinite(v) ? v : 0);
            });
        }
        if (cats.length === 0) return null;

        vals = defs.map((d, si) =>
            cats.map((_, ci) => aggregateVals(raw[si][ci], d.aggregation)),
        );
    }

    // Chronological order for a bucketed date X (keys sort in time order).
    if (isTemporal(xField)) {
        const order = cats
            .map((_, i) => i)
            .sort((a, b) =>
                cats[a] < cats[b] ? -1 : cats[a] > cats[b] ? 1 : 0,
            );
        const sortedCats = order.map((i) => cats[i]);
        cats.length = 0;
        cats.push(...sortedCats);
        vals = vals.map((row) => order.map((i) => row[i]));
    }

    // Stacked-area ("marea") combo: every series is an area and `stacked` asks
    // for cumulative bands (part-to-whole) instead of overlaid areas. The Y
    // scale then spans the TOTAL (column sum) and all series share the left axis
    // — a stacked area on a secondary scale is meaningless — so a right axis is
    // ignored here. Falls back to plain overlay unless there are ≥2 area series.
    const stacked =
        !!props.block.stacked &&
        defs.length >= 2 &&
        defs.every((d) => d.type === 'area');

    const hasRight = !stacked && defs.some((d) => d.axis === 'right');
    const columnTotals = cats.map((_, ci) =>
        defs.reduce((sum, _d, si) => sum + (vals[si][ci] || 0), 0),
    );
    // Round each axis up to a nice top so gridlines read as clean numbers,
    // consistent with the single line/area axis.
    const leftMax = niceCeil(
        stacked
            ? Math.max(1, ...columnTotals)
            : Math.max(
                  1,
                  ...defs.flatMap((d, si) =>
                      d.axis === 'right' ? [] : vals[si],
                  ),
              ),
    );
    const rightMax = niceCeil(
        Math.max(
            1,
            ...defs.flatMap((d, si) => (d.axis === 'right' ? vals[si] : [])),
        ),
    );

    // A combo with NO bars over a temporal X is a continuous line/area chart
    // (marea, overlaid lines/areas): lay it out like a full-width time-series
    // chart — a wide 760-unit viewBox (the chart-design proportions), points
    // spanning edge-to-edge, and date ticks thinned horizontally (~6 across).
    // The wide viewBox is what makes it read as the SAME FAMILY as the sibling
    // full-width charts: at the container width their 8px tick text and strokes
    // scale down the same amount (a narrow 360/520 viewBox would blow the text
    // and lines up when stretched full-width). Bars, or a non-temporal X, keep
    // the categorical layout (centred slots, labels rotated only when tight).
    const continuous =
        !defs.some((d) => d.type === 'bar') && isTemporal(xField);

    const W = continuous ? 760 : 360;
    const H = fitVH(W, continuous ? 300 : 210, 150, continuous ? 500 : 460);
    const padL = continuous ? 44 : 36;
    const padR = continuous ? (hasRight ? 44 : 20) : hasRight ? 36 : 12;
    const padT = continuous ? 16 : 10;
    const innerW = W - padL - padR;
    const n = cats.length;
    const slot = innerW / n;
    const axis = continuous
        ? {
              rotated: false,
              padB: 32,
              stride: Math.max(1, Math.ceil(n / 6)),
              trim: (s: string) => s,
          }
        : catAxisLayout(cats, slot, padL + slot / 2);
    const innerH = H - padT - axis.padB;
    const stepX = continuous ? (n <= 1 ? 0 : innerW / (n - 1)) : slot;
    const centerX = (ci: number) =>
        continuous
            ? padL + (n === 1 ? innerW / 2 : ci * stepX)
            : padL + slot * (ci + 0.5);
    const baselineY = padT + innerH;
    const yFor = (v: number, axis?: string) =>
        baselineY - (v / (axis === 'right' ? rightMax : leftMax)) * innerH;
    const color = (d: ComboSeries, si: number) => d.color ?? chartColor(si);

    // Grouped bars (one slot per category, bar series side by side). None in
    // stacked-area mode — every series is an area band.
    const barDefs = stacked
        ? []
        : defs.map((d, si) => ({ d, si })).filter((x) => x.d.type === 'bar');
    const groupCount = Math.max(1, barDefs.length);
    const barW = (slot * 0.64) / groupCount;
    const bars: {
        x: number;
        y: number;
        w: number;
        h: number;
        color: string;
    }[] = [];
    barDefs.forEach(({ d, si }, bIdx) => {
        cats.forEach((_, ci) => {
            const v = vals[si][ci];
            const y = yFor(v, d.axis);
            const groupLeft = centerX(ci) - (groupCount * barW) / 2;
            bars.push({
                x: groupLeft + bIdx * barW,
                y,
                w: Math.max(1, barW - 1),
                h: Math.max(0, baselineY - y),
                color: color(d, si),
            });
        });
    });

    // Line/area marks across category centres. In stacked mode each series is a
    // cumulative band; otherwise areas/lines overlay on a shared baseline.
    type ComboMark = {
        color: string;
        path: string;
        area: string | null;
        points: { x: number; y: number }[];
        fillOpacity: number;
    };
    let lines: ComboMark[];
    if (stacked) {
        // Cumulative bands, bottom-up in series order. Each band fills between
        // the running lower edge and lower+value; its upper edge is stroked so
        // the boundary between contributions reads crisply, and the hover
        // markers (points) sit on that edge at the cumulative height. Bands use
        // a solid-ish fill (unlike a single translucent area) so the parts stay
        // distinguishable.
        const cum = new Array<number>(cats.length).fill(0);
        lines = defs.map((d, si) => {
            const upper = cats.map((_, ci) => cum[ci] + (vals[si][ci] || 0));
            const lowerPts = cats.map((_, ci) => ({
                x: centerX(ci),
                y: yFor(cum[ci]),
            }));
            const upperPts = upper.map((v, ci) => ({
                x: centerX(ci),
                y: yFor(v),
            }));
            for (let ci = 0; ci < cats.length; ci++) {
                cum[ci] = upper[ci];
            }
            const upPath = smoothLine(upperPts);
            const revLower = [...lowerPts].reverse();
            // Smooth the reversed lower edge, then rewrite its leading `M x y`
            // into an `L x y` so it continues the upper path into one closed band.
            const lowTail = smoothLine(revLower).replace(
                /^M\s*-?[\d.]+\s+-?[\d.]+/,
                `L ${revLower[0].x} ${revLower[0].y}`,
            );
            return {
                color: color(d, si),
                path: upPath,
                area: upPath && lowTail ? `${upPath} ${lowTail} Z` : null,
                points: upperPts,
                fillOpacity: 0.82,
            };
        });
    } else {
        lines = defs
            .map((d, si) => ({ d, si }))
            .filter((x) => x.d.type !== 'bar')
            .map(({ d, si }) => {
                const pts = cats.map((_, ci) => ({
                    x: centerX(ci),
                    y: yFor(vals[si][ci], d.axis),
                }));
                const path = smoothLine(pts);
                const area =
                    d.type === 'area' && pts.length && path
                        ? `${path} L ${pts[pts.length - 1].x} ${baselineY} L ${pts[0].x} ${baselineY} Z`
                        : null;
                return {
                    color: color(d, si),
                    path,
                    area,
                    points: pts,
                    fillOpacity: 0.12,
                };
            });
    }

    const tickSet = (max: number, side: 'left' | 'right') =>
        niceAxis(max).ticks.map((v) => ({
            y: baselineY - (v / max) * innerH,
            label: formatNumber(v),
            x: side === 'left' ? padL - 5 : W - padR + 5,
            anchor: side === 'left' ? 'end' : 'start',
        }));

    return {
        W,
        H,
        padL,
        padR,
        top: padT,
        baselineY,
        stepX,
        xs: cats.map((_, ci) => centerX(ci)),
        bars,
        lines,
        leftTicks: tickSet(leftMax, 'left'),
        rightTicks: hasRight ? tickSet(rightMax, 'right') : null,
        catLabels: cats
            .map((c, ci) => ({ x: centerX(ci), label: axis.trim(c), ci }))
            .filter(
                (l) =>
                    l.ci % axis.stride === 0 || (continuous && l.ci === n - 1),
            ),
        rotatedCats: axis.rotated,
        // Per-category hover payload: every series' value there (for the tooltip)
        // and the line/area markers to highlight (bars show their own rect).
        hover: cats.map((cat, ci) => ({
            label: cat,
            rows: defs.map((d, si) => ({
                label: d.label ?? fieldOf(d.field_id)?.name ?? 'Count',
                color: color(d, si),
                value: formatNumber(vals[si][ci]),
            })),
            markers: lines.map((ln) => ({
                y: ln.points[ci]?.y ?? 0,
                color: ln.color,
            })),
        })),
        legend: defs.map((d, si) => ({
            label: d.label ?? fieldOf(d.field_id)?.name ?? 'Count',
            color: color(d, si),
            type: d.type,
            secondary: d.axis === 'right',
        })),
    };
});

// The active crosshair on a combo chart: shared-X category under the cursor with
// every series' value and the line markers to highlight.
const comboCrosshair = computed(() => {
    const c = combo.value;
    const i = activeIdx.value;
    if (!c || i === null || i < 0 || i >= c.hover.length) return null;
    const h = c.hover[i];
    return { x: c.xs[i], label: h.label, rows: h.rows, markers: h.markers };
});

// One tooltip payload for both the single line/area and the combo paths.
const activeCross = computed(() => crosshair.value ?? comboCrosshair.value);

// Empty state must account for combo (which ignores the single-series path).
const emptyState = computed(() => {
    if (props.block.chart_type === 'pareto') {
        return paretoItems.value.length === 0;
    }
    return isCombo.value ? combo.value === null : series.value.length === 0;
});

// Scatter: plot raw (x_field, y_field) points from each row.
const scatter = computed(() => {
    const rows = props.data?.rows ?? [];
    const xSlug = fieldOf(props.block.x_field_id)?.slug;
    const ySlug = yField.value?.slug;
    if (!xSlug || !ySlug) return null;
    const pts = rows
        .map((row) => ({
            x: Number(row.data[xSlug]),
            y: Number(row.data[ySlug]),
        }))
        .filter((p) => Number.isFinite(p.x) && Number.isFinite(p.y));
    if (pts.length === 0) return null;
    const xs = pts.map((p) => p.x);
    const ys = pts.map((p) => p.y);
    const [minX, maxX, minY, maxY] = [
        Math.min(...xs),
        Math.max(...xs),
        Math.min(...ys),
        Math.max(...ys),
    ];
    const w = 320;
    const h = 180;
    const pad = 18;
    const sx = (x: number) =>
        maxX === minX
            ? w / 2
            : pad + ((x - minX) / (maxX - minX)) * (w - 2 * pad);
    const sy = (y: number) =>
        maxY === minY
            ? h / 2
            : h - pad - ((y - minY) / (maxY - minY)) * (h - 2 * pad);
    return {
        w,
        h,
        points: pts.map((p) => ({ cx: sx(p.x), cy: sy(p.y), x: p.x, y: p.y })),
    };
});

// Sankey: a bipartite flow diagram — group_by_field_id is the SOURCE column and
// series_field_id the TARGET column; each (source, target) pair is a ribbon whose
// width is the aggregated value. Node heights are proportional to their total
// flow; both columns fill the height, so ribbons form trapezoids between them.
const sankey = computed(() => {
    if (props.block.chart_type !== 'sankey') return null;
    const srcField = groupField.value;
    const tgtField = seriesField.value;
    if (!srcField || !tgtField) return null;

    // Every (source, target) flow, with its aggregated width. A pivot IS a flow
    // table: {group: source, group2: target, value: width}.
    const flows: { source: string; target: string; value: number }[] = [];

    if (props.data?.groups) {
        for (const g of props.data.groups) {
            flows.push({
                source: formatGroupKey(g.group, srcField),
                target: formatGroupKey(g.group2, tgtField),
                value: Number(g.value) || 0,
            });
        }
    } else {
        const rows = props.data?.rows ?? [];
        const srcSlug = srcField.slug;
        const tgtSlug = tgtField.slug;
        const ySlug = yField.value?.slug;
        const agg = props.block.aggregation;

        const cells = new Map<string, Map<string, number[]>>();
        for (const r of rows) {
            const s = formatGroupKey(r.data[srcSlug], srcField);
            const tg = formatGroupKey(r.data[tgtSlug], tgtField);
            const v = ySlug ? Number(r.data[ySlug] ?? 0) : 1;
            if (!cells.has(s)) cells.set(s, new Map());
            const m = cells.get(s)!;
            if (!m.has(tg)) m.set(tg, []);
            m.get(tg)!.push(Number.isFinite(v) ? v : 0);
        }
        for (const [s, m] of cells) {
            for (const [tg, vals] of m) {
                flows.push({
                    source: s,
                    target: tg,
                    value: aggregateVals(vals, agg),
                });
            }
        }
    }

    const rawLinks: { source: string; target: string; value: number }[] = [];
    const srcTotals = new Map<string, number>();
    const tgtTotals = new Map<string, number>();
    for (const { source: s, target: tg, value } of flows) {
        if (value <= 0) continue;
        rawLinks.push({ source: s, target: tg, value });
        srcTotals.set(s, (srcTotals.get(s) ?? 0) + value);
        tgtTotals.set(tg, (tgtTotals.get(tg) ?? 0) + value);
    }
    if (rawLinks.length === 0) return null;

    const sources = [...srcTotals.keys()].sort(
        (a, b) => srcTotals.get(b)! - srcTotals.get(a)!,
    );
    const targets = [...tgtTotals.keys()].sort(
        (a, b) => tgtTotals.get(b)! - tgtTotals.get(a)!,
    );
    const totalFlow = [...srcTotals.values()].reduce((a, b) => a + b, 0);

    const W = 480;
    const rowCount = Math.max(sources.length, targets.length);
    const H = Math.max(160, Math.min(380, 24 + rowCount * 38));
    const padT = 10;
    const innerH = H - padT * 2;
    const gap = 10;
    const nodeW = 12;
    const leftX = 120;
    const rightX = W - 120 - nodeW;
    const scaleFor = (count: number) =>
        (innerH - gap * Math.max(0, count - 1)) / totalFlow;
    const sScale = scaleFor(sources.length);
    const tScale = scaleFor(targets.length);

    interface Node {
        name: string;
        x: number;
        y: number;
        h: number;
        total: number;
        cursor: number;
        color: string;
    }
    const srcNodes = new Map<string, Node>();
    let sy = padT;
    sources.forEach((name, i) => {
        const h = srcTotals.get(name)! * sScale;
        srcNodes.set(name, {
            name,
            x: leftX,
            y: sy,
            h,
            total: srcTotals.get(name)!,
            cursor: sy,
            color: paletteColorFor(srcField, name, i),
        });
        sy += h + gap;
    });
    const tgtNodes = new Map<string, Node>();
    let ty = padT;
    targets.forEach((name, i) => {
        const h = tgtTotals.get(name)! * tScale;
        tgtNodes.set(name, {
            name,
            x: rightX,
            y: ty,
            h,
            total: tgtTotals.get(name)!,
            cursor: ty,
            color: paletteColorFor(tgtField, name, sources.length + i),
        });
        ty += h + gap;
    });

    // Stack ribbons tidily: by source order, then target order.
    const srcIndex = new Map(sources.map((s, i) => [s, i]));
    const tgtIndex = new Map(targets.map((tg, i) => [tg, i]));
    rawLinks.sort(
        (a, b) =>
            srcIndex.get(a.source)! - srcIndex.get(b.source)! ||
            tgtIndex.get(a.target)! - tgtIndex.get(b.target)!,
    );

    const links = rawLinks.map((l) => {
        const sn = srcNodes.get(l.source)!;
        const tn = tgtNodes.get(l.target)!;
        const ths = l.value * sScale;
        const tht = l.value * tScale;
        const y0 = sn.cursor;
        const y1 = tn.cursor;
        sn.cursor += ths;
        tn.cursor += tht;
        const lx = sn.x + nodeW;
        const rx = tn.x;
        const mx = (lx + rx) / 2;
        const path =
            `M ${lx} ${y0} C ${mx} ${y0} ${mx} ${y1} ${rx} ${y1} ` +
            `L ${rx} ${y1 + tht} C ${mx} ${y1 + tht} ${mx} ${y0 + ths} ${lx} ${y0 + ths} Z`;
        return {
            path,
            color: sn.color,
            source: l.source,
            target: l.target,
            value: l.value,
        };
    });

    return {
        W,
        H,
        nodeW,
        links,
        srcNodes: [...srcNodes.values()],
        tgtNodes: [...tgtNodes.values()],
    };
});

/** Linear-interpolated quantile of an ASCENDING-sorted array. */
function quantile(sorted: number[], q: number): number {
    const n = sorted.length;
    if (n === 0) return 0;
    if (n === 1) return sorted[0];
    const pos = (n - 1) * q;
    const base = Math.floor(pos);
    const rest = pos - base;
    return sorted[base + 1] !== undefined
        ? sorted[base] + rest * (sorted[base + 1] - sorted[base])
        : sorted[base];
}

// Box-and-whisker: one box per group_by category summarising the DISTRIBUTION of
// the numeric y_field_id values in that group — box = Q1..Q3, line = median,
// whiskers reach the furthest values within 1.5·IQR (Tukey), points beyond are
// outliers. aggregation is ignored (the box IS the summary).
const boxPlot = computed(() => {
    if (props.block.chart_type !== 'box') return null;
    const rows = props.data?.rows ?? [];
    const catField = groupField.value;
    const ySlug = yField.value?.slug;
    if (!ySlug) return null; // a box plot needs a numeric field to summarise
    const catSlug = catField?.slug;

    const groups = new Map<string, number[]>();
    for (const r of rows) {
        const key = formatGroupKey(catSlug ? r.data[catSlug] : 'all', catField);
        const v = Number(r.data[ySlug]);
        if (!Number.isFinite(v)) continue;
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key)!.push(v);
    }
    if (groups.size === 0) return null;

    const cats = [...groups.keys()];
    const stats = cats.map((cat, i) => {
        const vals = groups
            .get(cat)!
            .slice()
            .sort((a, b) => a - b);
        const q1 = quantile(vals, 0.25);
        const med = quantile(vals, 0.5);
        const q3 = quantile(vals, 0.75);
        const iqr = q3 - q1;
        const loFence = q1 - 1.5 * iqr;
        const hiFence = q3 + 1.5 * iqr;
        const inRange = vals.filter((v) => v >= loFence && v <= hiFence);
        return {
            cat,
            q1,
            med,
            q3,
            whiskLo: inRange.length ? inRange[0] : q1,
            whiskHi: inRange.length ? inRange[inRange.length - 1] : q3,
            outliers: vals.filter((v) => v < loFence || v > hiFence),
            count: vals.length,
            color: colorFor(cat, i),
        };
    });

    const allVals = stats.flatMap((s) => [s.whiskLo, s.whiskHi, ...s.outliers]);
    let dmin = Math.min(...allVals);
    let dmax = Math.max(...allVals);
    if (dmin === dmax) dmax = dmin + 1;
    const domPad = (dmax - dmin) * 0.05;
    dmin -= domPad;
    dmax += domPad;

    const W = 480;
    const H = 240;
    const padL = 38;
    const padR = 12;
    const padT = 12;
    const innerW = W - padL - padR;
    const n = cats.length;
    const slot = innerW / n;
    const axis = catAxisLayout(cats, slot, padL + slot / 2);
    const innerH = H - padT - axis.padB;
    const baselineY = padT + innerH;
    const centerX = (i: number) => padL + slot * (i + 0.5);
    const boxW = Math.min(46, slot * 0.5);
    const yFor = (v: number) =>
        baselineY - ((v - dmin) / (dmax - dmin)) * innerH;

    const boxes = stats.map((s, i) => ({
        ...s,
        cx: centerX(i),
        x: centerX(i) - boxW / 2,
        boxW,
        capW: boxW * 0.6,
        yQ1: yFor(s.q1),
        yQ3: yFor(s.q3),
        yMed: yFor(s.med),
        yLo: yFor(s.whiskLo),
        yHi: yFor(s.whiskHi),
        outPts: s.outliers.map((v) => ({ y: yFor(v), value: v })),
    }));

    const yTicks = [0, 0.5, 1].map((f) => ({
        y: baselineY - f * innerH,
        label: formatNumber(dmin + f * (dmax - dmin)),
    }));
    const stride = axis.rotated ? axis.stride : Math.max(1, Math.ceil(n / 8));
    const xLabels = cats
        .map((label, i) => ({ x: centerX(i), label: axis.trim(label), i }))
        .filter((l) => l.i % stride === 0 || l.i === n - 1);

    return {
        W,
        H,
        padL,
        padR,
        baselineY,
        boxes,
        yTicks,
        xLabels,
        rotatedCats: axis.rotated,
    };
});
</script>

<template>
    <div
        ref="card"
        :class="[
            'relative flex flex-col rounded-sp-sm border p-5',
            t.surface,
            t.text,
        ]"
        @mousemove="onMove"
        @mouseleave="hideTip"
    >
        <!-- Floating tooltip shared by every chart type; follows the cursor. -->
        <div
            v-if="tip"
            class="pointer-events-none absolute z-20 rounded-sp-sm border border-medium bg-navy-elevated px-2.5 py-1.5 text-[11px] shadow-xl"
            :style="{ left: mouse.x + 12 + 'px', top: mouse.y + 12 + 'px' }"
        >
            <span class="flex items-center gap-1.5">
                <span
                    v-if="tip.color"
                    class="size-2 shrink-0 rounded-full"
                    :style="{ background: tip.color }"
                />
                <span :class="t.text">{{ tip.title }}</span>
            </span>
            <span
                v-if="tip.value"
                :class="['mt-0.5 block font-semibold tabular-nums', t.text]"
                >{{ tip.value }}</span
            >
        </div>
        <!-- Crosshair tooltip for line/area/combo: the hovered category and
             every series' value there, following the cursor. -->
        <div
            v-if="activeCross"
            class="pointer-events-none absolute z-20 rounded-sp-sm border border-medium bg-navy-elevated px-3 py-2 text-[11px] shadow-xl"
            :style="{ left: mouse.x + 14 + 'px', top: mouse.y + 14 + 'px' }"
        >
            <p :class="['mb-1 font-medium', t.textSubtle]">
                {{ activeCross.label }}
            </p>
            <div
                v-for="(r, ri) in activeCross.rows"
                :key="'ct' + ri"
                class="flex items-center gap-3 whitespace-nowrap"
            >
                <span class="flex items-center gap-1.5">
                    <span
                        class="h-2.5 w-1 shrink-0 rounded-full"
                        :style="{ background: r.color }"
                    />
                    <span :class="t.text">{{ r.label }}</span>
                </span>
                <span :class="['ml-auto font-semibold tabular-nums', t.text]">{{
                    r.value
                }}</span>
            </div>
        </div>
        <header v-if="block.label" class="mb-3">
            <div class="flex items-center justify-between">
                <p
                    :class="[
                        'text-[11px] tracking-wider uppercase',
                        t.textSubtle,
                    ]"
                >
                    {{ block.label }}
                </p>
            </div>
            <!-- One executive line: what the chart shows and how to read it —
                 styled as the card's real headline (the label above is the
                 eyebrow). -->
            <p
                v-if="block.description"
                :class="[
                    'mt-0.5 leading-snug font-semibold first-letter:uppercase',
                    t.textMuted,
                ]"
                style="font-size: medium"
            >
                {{ block.description }}
            </p>
        </header>

        <!-- Content fills the card's (equal-height) cell and centres, so a short
             chart never leaves an empty gap below it in a row of taller cards.
             min-h-0 lets an explicit card height SHRINK the plot area too. -->
        <div class="flex min-h-0 flex-1 flex-col justify-center">
            <p
                v-if="emptyState"
                :class="['py-8 text-center text-xs', t.textMuted]"
            >
                No data to plot.
            </p>

            <!-- Pareto: dedicated reusable component (vital few, threshold, badge) -->
            <ParetoChart
                v-else-if="block.chart_type === 'pareto' && paretoItems.length"
                :items="paretoItems"
                :accent="chartColor(0)"
                :line-color="chartColor(1)"
                :measure-label="paretoMeasureLabel"
                :category-noun="paretoNoun"
                :fit-height="hasExplicitHeight"
                @select="onDrill"
            />

            <!-- Combo: bars + lines/areas on a shared X, with an optional 2nd Y axis -->
            <template v-else-if="combo">
                <ul class="mb-3 flex flex-wrap gap-x-3 gap-y-1 text-[11px]">
                    <li
                        v-for="(s, i) in combo.legend"
                        :key="i"
                        class="flex items-center gap-1.5"
                    >
                        <span
                            class="inline-block shrink-0 rounded-xs"
                            :class="
                                s.type === 'bar' ? 'size-2.5' : 'h-0.5 w-3.5'
                            "
                            :style="{ background: s.color }"
                        />
                        <span class="truncate" :class="t.text">{{
                            s.label
                        }}</span>
                        <span
                            v-if="s.secondary"
                            :class="['text-[10px]', t.textMuted]"
                            >(eje der.)</span
                        >
                    </li>
                </ul>
                <svg
                    ref="chartHostEl"
                    :viewBox="`0 0 ${combo.W} ${combo.H}`"
                    class="min-h-0 w-full flex-1"
                    :class="t.text"
                >
                    <!-- left-axis gridlines + tick labels -->
                    <g
                        v-for="(tk, i) in combo.leftTicks"
                        :key="'lt' + i"
                        :class="t.textMuted"
                    >
                        <line
                            :x1="combo.padL"
                            :y1="tk.y"
                            :x2="combo.W - combo.padR"
                            :y2="tk.y"
                            stroke="currentColor"
                            stroke-opacity="0.1"
                        />
                        <text
                            :x="tk.x"
                            :y="tk.y + 3"
                            text-anchor="end"
                            fill="currentColor"
                            fill-opacity="0.7"
                            style="font-size: 8px"
                        >
                            {{ tk.label }}
                        </text>
                    </g>
                    <!-- right-axis tick labels -->
                    <text
                        v-for="(tk, i) in combo.rightTicks ?? []"
                        :key="'rt' + i"
                        :x="tk.x"
                        :y="tk.y + 3"
                        text-anchor="start"
                        fill="currentColor"
                        fill-opacity="0.7"
                        style="font-size: 8px"
                    >
                        {{ tk.label }}
                    </text>
                    <!-- bars -->
                    <rect
                        v-for="(b, i) in combo.bars"
                        :key="'b' + i"
                        :x="b.x"
                        :y="b.y"
                        :width="b.w"
                        :height="b.h"
                        :fill="b.color"
                        rx="1"
                    />
                    <!-- area fills then smoothed line strokes -->
                    <template v-for="(ln, i) in combo.lines" :key="'l' + i">
                        <path
                            v-if="ln.area"
                            :d="ln.area"
                            :fill="ln.color"
                            :fill-opacity="ln.fillOpacity"
                        />
                        <path
                            :d="ln.path"
                            fill="none"
                            :stroke="ln.color"
                            stroke-width="3"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                        <!-- point markers on line series (not area/stacked
                             bands), matching the single line chart — only when
                             they stay readable. -->
                        <template v-if="!ln.area && ln.points.length <= 24">
                            <circle
                                v-for="(p, pi) in ln.points"
                                :key="'cp' + i + '-' + pi"
                                :cx="p.x"
                                :cy="p.y"
                                r="3.2"
                                fill="var(--sp-bg-secondary, #fff)"
                                :stroke="ln.color"
                                stroke-width="2"
                            />
                        </template>
                    </template>
                    <!-- crosshair: dashed guide + line markers on the hovered category -->
                    <g v-if="comboCrosshair">
                        <line
                            :class="t.textMuted"
                            :x1="comboCrosshair.x"
                            :y1="combo.top"
                            :x2="comboCrosshair.x"
                            :y2="combo.baselineY"
                            stroke="currentColor"
                            stroke-opacity="0.45"
                            stroke-width="1"
                            stroke-dasharray="3 3"
                        />
                        <circle
                            v-for="(m, mi) in comboCrosshair.markers"
                            :key="'cm' + mi"
                            :cx="comboCrosshair.x"
                            :cy="m.y"
                            r="4.2"
                            fill="var(--sp-bg-secondary, #fff)"
                            :stroke="m.color"
                            stroke-width="2.5"
                        />
                    </g>
                    <!-- transparent full-height hover bands, one per category -->
                    <rect
                        v-for="(x, i) in combo.xs"
                        :key="'chb' + i"
                        :x="x - combo.stepX / 2"
                        :y="combo.top"
                        :width="combo.stepX"
                        :height="combo.baselineY - combo.top"
                        fill="transparent"
                        class="cursor-crosshair"
                        @mouseenter="activeIdx = i"
                        @mousemove="activeIdx = i"
                    />
                    <!-- x labels: horizontal when they fit, truncated diagonals when not -->
                    <text
                        v-for="(c, i) in combo.catLabels"
                        :key="'x' + i"
                        :x="c.x"
                        :y="combo.baselineY + 14"
                        :text-anchor="combo.rotatedCats ? 'end' : 'middle'"
                        :transform="
                            combo.rotatedCats
                                ? `rotate(-45 ${c.x} ${combo.baselineY + 14})`
                                : undefined
                        "
                        fill="currentColor"
                        fill-opacity="0.7"
                        style="font-size: 8px"
                    >
                        {{ c.label }}
                    </text>
                </svg>
            </template>

            <template
                v-else-if="
                    block.chart_type === 'pie' || block.chart_type === 'donut'
                "
            >
                <div
                    class="flex items-center gap-6"
                    :class="hasExplicitHeight ? 'min-h-0 flex-1' : ''"
                >
                    <svg
                        viewBox="0 0 160 160"
                        class="shrink-0"
                        :class="
                            hasExplicitHeight
                                ? 'aspect-square h-full max-h-full w-auto'
                                : 'size-32'
                        "
                    >
                        <path
                            v-for="(slice, i) in pieSlices"
                            :key="i"
                            :d="slice.path"
                            :fill="slice.color"
                            stroke="rgba(0,0,0,0.15)"
                            stroke-width="0.5"
                            class="cursor-pointer transition-opacity hover:opacity-80"
                            @click="onDrill(slice.label)"
                            @mouseenter="
                                showTip(
                                    slice.label,
                                    formatNumber(slice.value) +
                                        ' · ' +
                                        Math.round(slice.percent * 100) +
                                        '%',
                                    slice.color,
                                )
                            "
                        />
                        <circle
                            v-if="block.chart_type === 'donut'"
                            cx="80"
                            cy="80"
                            r="36"
                            fill="var(--sp-bg-secondary, #ffffff)"
                        />
                        <!-- Donut center total: the sum the slice %s are of -->
                        <template v-if="block.chart_type === 'donut'">
                            <text
                                x="80"
                                y="79"
                                text-anchor="middle"
                                fill="currentColor"
                                style="font-size: 17px; font-weight: 700"
                            >
                                {{ formatNumber(totalValue) }}
                            </text>
                            <text
                                x="80"
                                y="92"
                                text-anchor="middle"
                                fill="currentColor"
                                fill-opacity="0.5"
                                style="
                                    font-size: 7px;
                                    letter-spacing: 0.08em;
                                    text-transform: uppercase;
                                "
                            >
                                {{ donutCenterLabel }}
                            </text>
                        </template>
                    </svg>
                    <ul class="flex-1 space-y-1 text-xs">
                        <li
                            v-for="(slice, i) in pieSlices"
                            :key="i"
                            class="flex cursor-default items-center justify-between gap-2 rounded-xs px-1 transition-colors hover:bg-surface"
                            @mouseenter="
                                showTip(
                                    slice.label,
                                    formatNumber(slice.value) +
                                        ' · ' +
                                        Math.round(slice.percent * 100) +
                                        '%',
                                    slice.color,
                                )
                            "
                        >
                            <span class="flex min-w-0 items-center gap-2">
                                <span
                                    class="size-2.5 shrink-0 rounded-xs"
                                    :style="{ background: slice.color }"
                                />
                                <span class="truncate" :class="t.text">{{
                                    slice.label
                                }}</span>
                            </span>
                            <span :class="t.textMuted">
                                {{ formatNumber(slice.value) }}
                                <span class="ml-1 text-[10px] opacity-70"
                                    >{{
                                        Math.round(slice.percent * 100)
                                    }}%</span
                                >
                            </span>
                        </li>
                    </ul>
                </div>
            </template>

            <template v-else-if="lineChart">
                <!-- Series legend (only when there's more than one line) -->
                <ul
                    v-if="!lineChart.single"
                    class="mb-3 flex flex-wrap gap-x-3 gap-y-1 text-[11px]"
                >
                    <li
                        v-for="(s, i) in lineChart.series"
                        :key="i"
                        class="flex items-center gap-1.5"
                    >
                        <span
                            class="inline-block h-0.5 w-3.5 shrink-0 rounded-xs"
                            :style="{ background: s.color }"
                        />
                        <span class="truncate" :class="t.text">{{
                            s.label
                        }}</span>
                    </li>
                </ul>
                <div
                    ref="chartHostEl"
                    class="flex-1"
                    :class="hasExplicitHeight ? 'min-h-0' : 'min-h-[14rem]'"
                >
                    <svg
                        :viewBox="`0 0 ${lineChart.W} ${lineChart.H}`"
                        class="h-full w-full"
                        preserveAspectRatio="xMidYMid meet"
                        :class="t.text"
                    >
                        <!-- Soft vertical gradient for the area fill (line + area
                             alike) — the colour fades to transparent downward. -->
                        <defs>
                            <linearGradient
                                v-for="(s, gi) in lineChart.series"
                                :key="'grad' + gi"
                                :id="`${gradientId}-${gi}`"
                                x1="0"
                                y1="0"
                                x2="0"
                                y2="1"
                            >
                                <stop
                                    offset="0%"
                                    :stop-color="s.color"
                                    stop-opacity="0.22"
                                />
                                <stop
                                    offset="60%"
                                    :stop-color="s.color"
                                    stop-opacity="0.05"
                                />
                                <stop
                                    offset="100%"
                                    :stop-color="s.color"
                                    stop-opacity="0"
                                />
                            </linearGradient>
                        </defs>
                        <!-- horizontal gridlines + y-axis ticks -->
                        <g
                            v-for="(tk, i) in lineChart.yTicks"
                            :key="'y' + i"
                            :class="t.textMuted"
                        >
                            <line
                                :x1="lineChart.padL"
                                :y1="tk.y"
                                :x2="lineChart.W - lineChart.padR"
                                :y2="tk.y"
                                stroke="currentColor"
                                stroke-opacity="0.1"
                            />
                            <text
                                :x="lineChart.padL - 5"
                                :y="tk.y + 3"
                                text-anchor="end"
                                fill="currentColor"
                                fill-opacity="0.6"
                                style="font-size: 8px"
                            >
                                {{ tk.label }}
                            </text>
                        </g>
                        <!-- Mean reference for a single series: "where the
                             period sits" as a faint dashed rule. -->
                        <line
                            v-if="lineChart.avgY !== null"
                            :x1="lineChart.padL"
                            :y1="lineChart.avgY"
                            :x2="lineChart.W - lineChart.padR"
                            :y2="lineChart.avgY"
                            :class="t.textMuted"
                            stroke="currentColor"
                            stroke-opacity="0.35"
                            stroke-width="1"
                            stroke-dasharray="4 4"
                        />
                        <!-- Each series. The gradient fill is what makes an
                             AREA chart an area chart — a line chart stays an
                             open stroke with point markers, so the two types
                             read differently at a glance. -->
                        <template
                            v-for="(s, si) in lineChart.series"
                            :key="'s' + si"
                        >
                            <path
                                v-if="s.area && block.chart_type === 'area'"
                                :d="s.area"
                                :fill="`url(#${gradientId}-${si})`"
                            />
                            <path
                                :d="s.line"
                                fill="none"
                                :stroke="s.color"
                                stroke-width="3"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                            <!-- point markers on every line chart, single or
                                 multi-series, when they stay readable. A
                                 surface-filled dot with a coloured ring reads as
                                 a crisp node on the line. -->
                            <template
                                v-if="
                                    block.chart_type === 'line' &&
                                    s.points.length <= 24
                                "
                            >
                                <circle
                                    v-for="(p, pi) in s.points"
                                    :key="'p' + pi"
                                    :cx="p.x"
                                    :cy="p.y"
                                    r="3.2"
                                    fill="var(--sp-bg-secondary, #fff)"
                                    :stroke="s.color"
                                    stroke-width="2"
                                />
                            </template>
                        </template>
                        <!-- crosshair: dashed vertical guide + markers on the
                             hovered category, across every series -->
                        <g v-if="crosshair">
                            <line
                                :class="t.textMuted"
                                :x1="crosshair.x"
                                :y1="lineChart.top"
                                :x2="crosshair.x"
                                :y2="lineChart.baselineY"
                                stroke="currentColor"
                                stroke-opacity="0.45"
                                stroke-width="1"
                                stroke-dasharray="3 3"
                            />
                            <circle
                                v-for="(r, ri) in crosshair.rows"
                                :key="'m' + ri"
                                :cx="crosshair.x"
                                :cy="r.y"
                                r="4.2"
                                fill="var(--sp-bg-secondary, #fff)"
                                :stroke="r.color"
                                stroke-width="2.5"
                            />
                        </g>
                        <!-- transparent full-height hover bands, one per
                             category, so anywhere over a column arms the crosshair -->
                        <rect
                            v-for="(x, i) in lineChart.xs"
                            :key="'hb' + i"
                            :x="x - (lineChart.stepX || lineChart.W) / 2"
                            :y="lineChart.top"
                            :width="lineChart.stepX || lineChart.W"
                            :height="lineChart.baselineY - lineChart.top"
                            fill="transparent"
                            class="cursor-crosshair"
                            @mouseenter="activeIdx = i"
                            @mousemove="activeIdx = i"
                        />
                        <!-- x-axis labels (thinned) -->
                        <text
                            v-for="(l, i) in lineChart.xLabels"
                            :key="'x' + i"
                            :x="l.x"
                            :y="lineChart.baselineY + 15"
                            text-anchor="middle"
                            fill="currentColor"
                            fill-opacity="0.6"
                            style="font-size: 8px"
                        >
                            {{ l.label }}
                        </text>
                    </svg>
                </div>
            </template>

            <!-- hbar: ranked bars via the dedicated reusable component -->
            <HBarChart
                v-else-if="block.chart_type === 'hbar'"
                :items="series"
                :accent="chartColor(0)"
                :measure-label="paretoMeasureLabel"
                :category-noun="paretoNoun"
                :clickable="!!block.drill_param"
                :fit-height="hasExplicitHeight"
                @select="onDrill"
            />

            <template v-else-if="block.chart_type === 'radar' && radar">
                <!-- Series legend (only when multiple polygons are overlaid) -->
                <ul
                    v-if="!radar.single"
                    class="mb-2 flex flex-wrap justify-center gap-x-3 gap-y-1 text-[11px]"
                >
                    <li
                        v-for="(p, i) in radar.polys"
                        :key="i"
                        class="flex items-center gap-1.5"
                    >
                        <span
                            class="inline-block size-2.5 shrink-0 rounded-xs"
                            :style="{ background: p.color }"
                        />
                        <span class="truncate" :class="t.text">{{
                            p.label
                        }}</span>
                    </li>
                </ul>
                <svg
                    viewBox="0 0 220 220"
                    preserveAspectRatio="xMidYMid meet"
                    class="mx-auto"
                    :class="[
                        t.text,
                        hasExplicitHeight
                            ? 'aspect-square h-full min-h-0 w-auto max-w-full flex-1'
                            : 'w-full max-w-[280px]',
                    ]"
                >
                    <circle
                        v-for="ring in [0.34, 0.67, 1]"
                        :key="ring"
                        :cx="radar.cx"
                        :cy="radar.cy"
                        :r="radar.r * ring"
                        fill="none"
                        stroke="currentColor"
                        stroke-opacity="0.12"
                    />
                    <line
                        v-for="(a, i) in radar.axes"
                        :key="'ax' + i"
                        :x1="radar.cx"
                        :y1="radar.cy"
                        :x2="a.ax"
                        :y2="a.ay"
                        stroke="currentColor"
                        stroke-opacity="0.12"
                    />
                    <!-- one filled polygon + points per series -->
                    <template v-for="(p, pi) in radar.polys" :key="'poly' + pi">
                        <path
                            :d="p.d"
                            :style="{ fill: p.color, stroke: p.color }"
                            :fill-opacity="radar.single ? 0.2 : 0.12"
                            stroke-width="2"
                        />
                        <circle
                            v-for="(pt, i) in p.points"
                            :key="'pt' + pi + '-' + i"
                            :cx="pt.x"
                            :cy="pt.y"
                            r="3"
                            class="cursor-pointer transition-all hover:[r:5px]"
                            :style="{ fill: p.color }"
                            @mouseenter="
                                showTip(
                                    (p.label ? p.label + ' · ' : '') + pt.axis,
                                    formatNumber(pt.value),
                                    p.color,
                                )
                            "
                        />
                    </template>
                    <text
                        v-for="(a, i) in radar.axes"
                        :key="'lb' + i"
                        :x="a.lx"
                        :y="a.ly"
                        text-anchor="middle"
                        dominant-baseline="middle"
                        fill="currentColor"
                        fill-opacity="0.7"
                        style="font-size: 8px"
                    >
                        {{ a.label }}
                    </text>
                </svg>
            </template>

            <!-- Treemap: dedicated reusable component (wrapped labels, value+share) -->
            <TreemapChart
                v-else-if="block.chart_type === 'treemap' && series.length"
                :items="series"
                :colors="[0, 1, 2, 3, 4, 5].map((i) => chartColor(i))"
                :clickable="!!block.drill_param"
                :fit-height="hasExplicitHeight"
                @select="onDrill"
            />

            <template v-else-if="block.chart_type === 'scatter' && scatter">
                <svg
                    :viewBox="`0 0 ${scatter.w} ${scatter.h}`"
                    preserveAspectRatio="xMidYMid meet"
                    class="w-full"
                    :class="[
                        t.text,
                        hasExplicitHeight ? 'h-full min-h-0 flex-1' : '',
                    ]"
                >
                    <line
                        x1="18"
                        :y1="scatter.h - 18"
                        :x2="scatter.w - 8"
                        :y2="scatter.h - 18"
                        stroke="currentColor"
                        stroke-opacity="0.15"
                    />
                    <line
                        x1="18"
                        y1="8"
                        x2="18"
                        :y2="scatter.h - 18"
                        stroke="currentColor"
                        stroke-opacity="0.15"
                    />
                    <circle
                        v-for="(p, i) in scatter.points"
                        :key="i"
                        :cx="p.cx"
                        :cy="p.cy"
                        r="3.5"
                        fill-opacity="0.7"
                        class="cursor-pointer transition-all hover:[fill-opacity:1] hover:[r:5px]"
                        :style="{ fill: 'var(--sp-accent, #3B82F6)' }"
                        @mouseenter="
                            showTip(
                                formatNumber(p.x) + ', ' + formatNumber(p.y),
                            )
                        "
                    />
                </svg>
            </template>

            <template v-else-if="block.chart_type === 'sankey'">
                <!-- sankey: source column → target column flow ribbons -->
                <svg
                    v-if="sankey"
                    :viewBox="`0 0 ${sankey.W} ${sankey.H}`"
                    preserveAspectRatio="xMidYMid meet"
                    class="w-full"
                    :class="[
                        t.text,
                        hasExplicitHeight ? 'h-full min-h-0 flex-1' : '',
                    ]"
                >
                    <path
                        v-for="(lk, i) in sankey.links"
                        :key="'lk' + i"
                        :d="lk.path"
                        :fill="lk.color"
                        fill-opacity="0.35"
                        class="cursor-pointer transition-all hover:[fill-opacity:0.6]"
                        @mouseenter="
                            showTip(
                                lk.source + ' → ' + lk.target,
                                formatNumber(lk.value),
                                lk.color,
                            )
                        "
                    />
                    <g v-for="(n, i) in sankey.srcNodes" :key="'sn' + i">
                        <rect
                            :x="n.x"
                            :y="n.y"
                            :width="sankey.nodeW"
                            :height="Math.max(1, n.h)"
                            :fill="n.color"
                            rx="1"
                            class="cursor-pointer transition-opacity hover:opacity-80"
                            @mouseenter="
                                showTip(n.name, formatNumber(n.total), n.color)
                            "
                        />
                        <text
                            :x="n.x - 6"
                            :y="n.y + n.h / 2 + 3"
                            text-anchor="end"
                            fill="currentColor"
                            fill-opacity="0.75"
                            style="font-size: 9px"
                        >
                            {{ n.name }}
                        </text>
                    </g>
                    <g v-for="(n, i) in sankey.tgtNodes" :key="'tn' + i">
                        <rect
                            :x="n.x"
                            :y="n.y"
                            :width="sankey.nodeW"
                            :height="Math.max(1, n.h)"
                            :fill="n.color"
                            rx="1"
                            class="cursor-pointer transition-opacity hover:opacity-80"
                            @mouseenter="
                                showTip(n.name, formatNumber(n.total), n.color)
                            "
                        />
                        <text
                            :x="n.x + sankey.nodeW + 6"
                            :y="n.y + n.h / 2 + 3"
                            text-anchor="start"
                            fill="currentColor"
                            fill-opacity="0.75"
                            style="font-size: 9px"
                        >
                            {{ n.name }}
                        </text>
                    </g>
                </svg>
                <p v-else :class="['py-8 text-center text-xs', t.textMuted]">
                    Sankey needs a group-by (source) and a series (target)
                    field.
                </p>
            </template>

            <template v-else-if="block.chart_type === 'box'">
                <!-- box-and-whisker: distribution of y_field per group_by category -->
                <div
                    v-if="boxPlot"
                    :class="hasExplicitHeight ? 'min-h-0 flex-1' : 'h-64'"
                >
                    <svg
                        :viewBox="`0 0 ${boxPlot.W} ${boxPlot.H}`"
                        class="h-full w-full"
                        preserveAspectRatio="xMidYMid meet"
                        :class="t.text"
                    >
                        <!-- y gridlines + ticks -->
                        <g
                            v-for="(tk, i) in boxPlot.yTicks"
                            :key="'y' + i"
                            :class="t.textMuted"
                        >
                            <line
                                :x1="boxPlot.padL"
                                :y1="tk.y"
                                :x2="boxPlot.W - boxPlot.padR"
                                :y2="tk.y"
                                stroke="currentColor"
                                stroke-opacity="0.1"
                            />
                            <text
                                :x="boxPlot.padL - 5"
                                :y="tk.y + 3"
                                text-anchor="end"
                                fill="currentColor"
                                fill-opacity="0.6"
                                style="font-size: 8px"
                            >
                                {{ tk.label }}
                            </text>
                        </g>
                        <g v-for="(b, i) in boxPlot.boxes" :key="'box' + i">
                            <!-- whisker line + caps -->
                            <line
                                :x1="b.cx"
                                :y1="b.yHi"
                                :x2="b.cx"
                                :y2="b.yLo"
                                :stroke="b.color"
                                stroke-width="1.5"
                            />
                            <line
                                :x1="b.cx - b.capW / 2"
                                :y1="b.yHi"
                                :x2="b.cx + b.capW / 2"
                                :y2="b.yHi"
                                :stroke="b.color"
                                stroke-width="1.5"
                            />
                            <line
                                :x1="b.cx - b.capW / 2"
                                :y1="b.yLo"
                                :x2="b.cx + b.capW / 2"
                                :y2="b.yLo"
                                :stroke="b.color"
                                stroke-width="1.5"
                            />
                            <!-- box Q1..Q3 -->
                            <rect
                                :x="b.x"
                                :y="b.yQ3"
                                :width="b.boxW"
                                :height="Math.max(1, b.yQ1 - b.yQ3)"
                                :fill="b.color"
                                fill-opacity="0.25"
                                :stroke="b.color"
                                stroke-width="1.5"
                                rx="1"
                                class="cursor-pointer transition-all hover:[fill-opacity:0.4]"
                                @mouseenter="
                                    showTip(
                                        b.cat + ' (n=' + b.count + ')',
                                        'mediana ' +
                                            formatNumber(b.med) +
                                            ' · Q1–Q3 ' +
                                            formatNumber(b.q1) +
                                            '–' +
                                            formatNumber(b.q3),
                                        b.color,
                                    )
                                "
                            />
                            <!-- median -->
                            <line
                                :x1="b.x"
                                :y1="b.yMed"
                                :x2="b.x + b.boxW"
                                :y2="b.yMed"
                                :stroke="b.color"
                                stroke-width="2.5"
                            />
                            <!-- outliers -->
                            <circle
                                v-for="(o, oi) in b.outPts"
                                :key="'o' + oi"
                                :cx="b.cx"
                                :cy="o.y"
                                r="2"
                                :fill="b.color"
                                fill-opacity="0.7"
                                class="cursor-pointer transition-all hover:[r:4px]"
                                @mouseenter="
                                    showTip(
                                        b.cat + ' · outlier',
                                        formatNumber(o.value),
                                        b.color,
                                    )
                                "
                            />
                        </g>
                        <!-- x labels: horizontal when they fit, truncated diagonals when not -->
                        <text
                            v-for="(l, i) in boxPlot.xLabels"
                            :key="'x' + i"
                            :x="l.x"
                            :y="boxPlot.baselineY + 15"
                            :text-anchor="
                                boxPlot.rotatedCats ? 'end' : 'middle'
                            "
                            :transform="
                                boxPlot.rotatedCats
                                    ? `rotate(-45 ${l.x} ${boxPlot.baselineY + 15})`
                                    : undefined
                            "
                            fill="currentColor"
                            fill-opacity="0.6"
                            style="font-size: 8px"
                        >
                            {{ l.label }}
                        </text>
                    </svg>
                </div>
                <p v-else :class="['py-8 text-center text-xs', t.textMuted]">
                    Box plot needs a numeric field (y_field_id) and a group-by
                    category.
                </p>
            </template>

            <template v-else-if="isMultiSeries && multi">
                <!-- multi-series bar: stacked or grouped segments per category -->
                <ul class="mb-3 flex flex-wrap gap-x-3 gap-y-1 text-[11px]">
                    <li
                        v-for="(s, i) in multi.series"
                        :key="i"
                        class="flex items-center gap-1.5"
                    >
                        <span
                            class="size-2.5 shrink-0 rounded-xs"
                            :style="{ background: s.color }"
                        />
                        <span class="truncate" :class="t.text">{{
                            s.label
                        }}</span>
                    </li>
                </ul>
                <div
                    class="flex items-end gap-3 px-2 pt-2"
                    :class="hasExplicitHeight ? 'min-h-0 flex-1' : 'h-56'"
                >
                    <div
                        v-for="(cat, ci) in multi.cats"
                        :key="cat"
                        class="flex h-full min-w-0 flex-1 items-end justify-center"
                    >
                        <!-- stacked: one column of stacked segments -->
                        <div
                            v-if="multi.stacked"
                            class="flex h-full w-full flex-col-reverse"
                        >
                            <div
                                v-for="(val, si) in multi.data[ci]"
                                :key="si"
                                class="w-full cursor-pointer transition-all first:rounded-t-xs hover:opacity-80"
                                :style="{
                                    height: (val / multi.max) * 100 + '%',
                                    background: multi.series[si].color,
                                }"
                                @mouseenter="
                                    showTip(
                                        multi.series[si].label + ' · ' + cat,
                                        formatNumber(val),
                                        multi.series[si].color,
                                    )
                                "
                            />
                        </div>
                        <!-- grouped: thin bars side by side -->
                        <div
                            v-else
                            class="flex h-full w-full items-end justify-center gap-0.5"
                        >
                            <div
                                v-for="(val, si) in multi.data[ci]"
                                :key="si"
                                class="min-w-0 flex-1 cursor-pointer rounded-t-xs transition-all hover:opacity-80"
                                :style="{
                                    height:
                                        Math.max(2, (val / multi.max) * 100) +
                                        '%',
                                    background: multi.series[si].color,
                                }"
                                @mouseenter="
                                    showTip(
                                        multi.series[si].label + ' · ' + cat,
                                        formatNumber(val),
                                        multi.series[si].color,
                                    )
                                "
                            />
                        </div>
                    </div>
                </div>
                <div class="flex gap-3 px-2 pt-1">
                    <div
                        v-for="cat in multi.cats"
                        :key="cat"
                        :class="[
                            'min-w-0 flex-1 truncate text-center text-[11px]',
                            t.text,
                        ]"
                        :title="cat"
                    >
                        {{ cat }}
                    </div>
                </div>
            </template>

            <template v-else>
                <!-- bar = vertical columns -->
                <div
                    class="flex items-end gap-3 px-2 pt-2"
                    :class="hasExplicitHeight ? 'min-h-0 flex-1' : 'h-56'"
                >
                    <div
                        v-for="(s, i) in series"
                        :key="s.label"
                        class="flex h-full min-w-0 flex-1 flex-col items-stretch justify-end gap-1"
                    >
                        <span
                            :class="[
                                'text-center text-[11px] tabular-nums',
                                t.textMuted,
                            ]"
                        >
                            {{ formatNumber(s.value) }}
                        </span>
                        <div
                            class="cursor-pointer rounded-t-xs transition-all hover:opacity-80"
                            :style="{
                                height:
                                    Math.max(2, (s.value / maxValue) * 100) +
                                    '%',
                                background: colorFor(s.label, i),
                            }"
                            @click="onDrill(s.label)"
                            @mouseenter="
                                showTip(
                                    s.label,
                                    formatNumber(s.value),
                                    colorFor(s.label, i),
                                )
                            "
                        />
                    </div>
                </div>
                <div class="flex gap-3 px-2 pt-1">
                    <div
                        v-for="s in series"
                        :key="s.label"
                        :class="[
                            'min-w-0 flex-1 truncate text-center text-[11px]',
                            t.text,
                        ]"
                        :title="s.label"
                    >
                        {{ s.label }}
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>
