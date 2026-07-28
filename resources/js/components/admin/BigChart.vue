<script setup lang="ts">
import { computed, ref } from 'vue';

/**
 * The main usage chart on the dashboard. Inline SVG with gridlines, area
 * fill, stroke, and an end dot. Two series overlaid (chat + embeddings)
 * with separate tints. No chart lib.
 *
 * Axis labels live in HTML around the plot rather than inside the SVG: the
 * SVG is drawn with `preserveAspectRatio="none"` so it stretches to any
 * container width, and text inside it would stretch too.
 */
interface Series {
    label: string;
    tint: string;
    points: number[];
}

interface Props {
    series: Series[];
    width?: number;
    height?: number;
    /** Value ticks on the y axis; also where the gridlines are drawn. */
    ticks?: number;
    /** One per point. Given, the x axis is drawn (thinned to fit). */
    labels?: string[];
    /**
     * One per point, for the hover readout. The x axis is thinned and short by
     * necessity; a tooltip has room to say which day AND which hour.
     */
    tooltipLabels?: string[];
    /** Renders a y-axis value. Defaults to a plain localised number. */
    formatValue?: (value: number) => string;
    maxXTicks?: number;
}

const props = withDefaults(defineProps<Props>(), {
    width: 720,
    height: 220,
    ticks: 4,
    labels: undefined,
    tooltipLabels: undefined,
    formatValue: (value: number) => new Intl.NumberFormat().format(value),
    maxXTicks: 6,
});

/** Vertical padding inside the viewBox, so the peak and the baseline breathe. */
const PAD = 10;

/**
 * Round a step up to something a person reads without thinking: 1, 1.5, 2 …
 * times a power of ten. Without it the axis labels are whatever a quarter of
 * the peak happens to be — $0.1327 and friends.
 */
function niceStep(value: number): number {
    if (!(value > 0)) return 1;
    const magnitude = Math.pow(10, Math.floor(Math.log10(value)));
    const normalised = value / magnitude;
    const step = [1, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10].find((s) => normalised <= s) ?? 10;
    return step * magnitude;
}

const peak = computed(() => Math.max(0, ...props.series.flatMap((s) => s.points)));

/**
 * The top of the scale, rounded so every tick is a round number. Deliberately
 * not floored at 1: a window whose entire spend is $0.40 was being drawn
 * against a $1 scale, which is why small periods all read as flat.
 */
const scaleMax = computed(() => (peak.value > 0 ? niceStep(peak.value / props.ticks) * props.ticks : 1));

/** Where a value sits vertically inside the viewBox. */
function yFor(value: number): number {
    return props.height - (value / scaleMax.value) * (props.height - PAD * 2) - PAD;
}

const yTicks = computed(() =>
    Array.from({ length: props.ticks + 1 }, (_, i) => {
        const value = (scaleMax.value / props.ticks) * i;
        const y = yFor(value);
        return { value, y, topPct: (y / props.height) * 100 };
    }),
);

/**
 * Every point cannot be labelled — 90 days would smear into each other — so
 * take evenly spaced ones, always including the first and the last.
 */
const xTicks = computed(() => {
    const labels = props.labels ?? [];
    if (labels.length === 0) return [];
    if (labels.length === 1) return [{ index: 0, label: labels[0], style: { left: '0%' } }];

    const count = Math.min(props.maxXTicks, labels.length);
    const indexes = [
        ...new Set(Array.from({ length: count }, (_, i) => Math.round((i * (labels.length - 1)) / (count - 1)))),
    ];

    return indexes.map((index) => {
        const fraction = index / (labels.length - 1);
        // The end labels anchor to the edges instead of centring, or half of
        // each would hang outside the plot.
        const style =
            index === 0
                ? { left: '0%' }
                : index === labels.length - 1
                  ? { right: '0%' }
                  : { left: `${fraction * 100}%`, transform: 'translateX(-50%)' };
        return { index, label: labels[index], style };
    });
});

/*
 * Hover readout. The plot is one stretched SVG, so the guide, the dots and the
 * tooltip are HTML positioned by fraction rather than SVG coordinates — the
 * same reason the axis labels live outside it.
 */
const pointCount = computed(() => Math.max(...props.series.map((s) => s.points.length), 0));
const hovered = ref<number | null>(null);

function trackPointer(event: PointerEvent): void {
    const total = pointCount.value;
    if (total === 0) {
        return;
    }
    const box = (event.currentTarget as HTMLElement).getBoundingClientRect();
    if (box.width === 0) {
        return;
    }
    // Nearest point, not the one to the left: hovering just past a peak should
    // read the peak.
    const fraction = Math.min(1, Math.max(0, (event.clientX - box.left) / box.width));
    hovered.value = total === 1 ? 0 : Math.round(fraction * (total - 1));
}

const readout = computed(() => {
    const index = hovered.value;
    if (index === null) return null;

    return {
        index,
        leftPct: pointCount.value > 1 ? (index / (pointCount.value - 1)) * 100 : 0,
        // Past the midpoint the tooltip flips to the other side of the guide,
        // or it would hang off the right edge of the card.
        flip: pointCount.value > 1 && index / (pointCount.value - 1) > 0.5,
        label: props.tooltipLabels?.[index] ?? props.labels?.[index] ?? null,
        rows: props.series.map((s) => {
            const value = s.points[index] ?? 0;
            return { label: s.label, tint: s.tint, value, topPct: (yFor(value) / props.height) * 100 };
        }),
    };
});

function toPath(points: number[]): { path: string; area: string; end: { x: number; y: number } | null } {
    if (points.length === 0) return { path: '', area: '', end: null };
    const stepX = points.length > 1 ? props.width / (points.length - 1) : 0;
    const coords = points.map((v, i) => ({ x: i * stepX, y: yFor(v) }));
    const path = coords.map((p, i) => `${i === 0 ? 'M' : 'L'} ${p.x.toFixed(2)} ${p.y.toFixed(2)}`).join(' ');
    // Closed on the zero line, not the viewBox floor: otherwise the fill sits
    // a padding's worth below the gridline it is meant to rest on.
    const base = yFor(0).toFixed(2);
    const area = `${path} L ${props.width.toFixed(2)} ${base} L 0 ${base} Z`;
    return { path, area, end: coords[coords.length - 1] };
}
</script>

<template>
    <div class="w-full">
        <div class="flex gap-2">
            <!-- y axis: labels share the gridlines' fraction of the height -->
            <div class="relative h-56 w-12 shrink-0">
                <span
                    v-for="t in yTicks"
                    :key="`y-${t.value}`"
                    class="absolute right-0 -translate-y-1/2 text-[10px] tabular-nums text-ink-subtle"
                    :style="{ top: `${t.topPct}%` }"
                >
                    {{ formatValue(t.value) }}
                </span>
            </div>

            <div
                class="relative min-w-0 flex-1"
                data-sp-chart-plot
                @pointermove="trackPointer"
                @pointerleave="hovered = null"
            >
                <svg
                    :viewBox="`0 0 ${width} ${height}`"
                    preserveAspectRatio="none"
                    class="h-56 w-full overflow-visible"
                    aria-hidden="true"
                >
                    <g stroke="var(--sp-border-soft)" stroke-dasharray="2 4">
                        <line v-for="t in yTicks" :key="`grid-${t.value}`" x1="0" :y1="t.y" :x2="width" :y2="t.y" />
                    </g>
                    <template v-for="s in series" :key="s.label">
                        <path :d="toPath(s.points).area" :fill="s.tint" fill-opacity="0.12" />
                        <path
                            :d="toPath(s.points).path"
                            :stroke="s.tint"
                            stroke-width="1.8"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            fill="none"
                        />
                        <circle
                            v-if="toPath(s.points).end"
                            :cx="toPath(s.points).end!.x"
                            :cy="toPath(s.points).end!.y"
                            r="3.5"
                            :fill="s.tint"
                        />
                    </template>
                </svg>

                <!-- hover: guide, a dot per series, and the readout -->
                <div
                    v-if="readout"
                    class="pointer-events-none absolute inset-y-0 w-px bg-medium"
                    :style="{ left: `${readout.leftPct}%` }"
                />
                <template v-if="readout">
                    <span
                        v-for="r in readout.rows"
                        :key="`hover-${r.label}`"
                        class="pointer-events-none absolute size-2 -translate-x-1/2 -translate-y-1/2 rounded-pill ring-2 ring-navy"
                        :style="{ left: `${readout.leftPct}%`, top: `${r.topPct}%`, backgroundColor: r.tint }"
                    />
                    <div
                        class="pointer-events-none absolute top-2 z-10 min-w-32 rounded-sp-sm border border-medium bg-navy/95 px-2.5 py-1.5 text-[11px] shadow-lg"
                        :style="
                            readout.flip
                                ? { right: `calc(100% - ${readout.leftPct}% + 8px)` }
                                : { left: `calc(${readout.leftPct}% + 8px)` }
                        "
                    >
                        <p v-if="readout.label" class="mb-1 font-medium text-ink">{{ readout.label }}</p>
                        <p
                            v-for="r in readout.rows"
                            :key="`tip-${r.label}`"
                            class="flex items-center justify-between gap-3 text-ink-muted"
                        >
                            <span class="flex items-center gap-1.5">
                                <span class="inline-block size-1.5 rounded-pill" :style="{ backgroundColor: r.tint }" />
                                {{ r.label }}
                            </span>
                            <span class="tabular-nums text-ink">{{ formatValue(r.value) }}</span>
                        </p>
                    </div>
                </template>
            </div>
        </div>

        <!-- x axis, offset past the y-label column (w-12 + gap-2) -->
        <div v-if="xTicks.length" class="relative ml-14 mt-1.5 h-3">
            <span
                v-for="t in xTicks"
                :key="`x-${t.index}`"
                class="absolute whitespace-nowrap text-[10px] tabular-nums text-ink-subtle"
                :style="t.style"
            >
                {{ t.label }}
            </span>
        </div>

        <ul class="mt-3 flex flex-wrap gap-4 text-xs text-ink-muted">
            <li v-for="s in series" :key="`legend-${s.label}`" class="flex items-center gap-1.5">
                <span class="inline-block size-2 rounded-pill" :style="{ backgroundColor: s.tint }" />
                {{ s.label }}
            </li>
        </ul>
    </div>
</template>
