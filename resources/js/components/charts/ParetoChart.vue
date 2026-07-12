<script setup lang="ts">
/**
 * Pareto chart — the "vital few" read as a first-class visual, self-contained
 * so any surface (app runtime, admin, slides) can drop it in with just
 * [{label, value}] items:
 *
 *  - bars sorted desc; the ones up to the threshold crossing are ACCENT
 *    (vital few) over a tinted band with a dashed divider; the rest muted
 *  - cumulative-% line on a fixed 0-100% right axis, % label on every point,
 *    dashed threshold line at the target (default 80%)
 *  - insight badge ("6 motivos = 80% del volumen · 1,385 en total"), legend,
 *    per-bar value labels, two-line wrapped category labels, hover tooltip
 *    with the full label, click-through for drill-downs
 */
import { useElementSize } from '@/composables/useElementSize';
import { resolveCssColor } from '@/lib/resolveCssColor';
import { computed, onMounted, ref, watch } from 'vue';

export interface ParetoItem {
    label: string;
    value: number;
}

const props = withDefaults(
    defineProps<{
        items: ParetoItem[];
        threshold?: number;
        accent?: string;
        lineColor?: string;
        measureLabel?: string;
        categoryNoun?: string;
        locale?: string;
        showValues?: boolean;
        showBadge?: boolean;
        footnote?: string | false;
        /** Fill the parent's height (letterbox the plot) instead of taking the
         *  viewBox's natural aspect height — set when the card has an explicit
         *  height so the chart shrinks/grows with it. */
        fitHeight?: boolean;
    }>(),
    {
        threshold: 80,
        accent: '#0059ff',
        lineColor: '#16a34a',
        measureLabel: 'Total',
        categoryNoun: 'categorías',
        locale: 'es-MX',
        showValues: true,
        showBadge: true,
        footnote: undefined,
        fitHeight: false,
    },
);

const emit = defineEmits<{ (e: 'select', label: string): void }>();

const hover = ref<number | null>(null);
const mouse = ref({ x: 0, y: 0 });

// Palette colors often arrive as `var(--sp-chart-N, #hex)` — resolve them
// against this element so tints, gradients and the vital band follow the
// org accent instead of the component defaults.
const rootEl = ref<HTMLElement | null>(null);
const accent = ref(props.accent);
const lineColor = ref(props.lineColor);
const syncColors = () => {
    accent.value = resolveCssColor(props.accent, rootEl.value);
    lineColor.value = resolveCssColor(props.lineColor, rootEl.value);
};
onMounted(syncColors);
watch(() => [props.accent, props.lineColor], syncColors);

function onMove(e: MouseEvent) {
    const el = (e.currentTarget as HTMLElement).getBoundingClientRect();
    mouse.value = { x: e.clientX - el.left, y: e.clientY - el.top };
}

const nf = computed(() => new Intl.NumberFormat(props.locale));
const fmtPct = (p: number) => `${Math.round(p * 10) / 10}%`;

function hexRgb(hex: string): [number, number, number] {
    const m = /^#?([0-9a-f]{6})$/i.exec(hex.trim());
    if (!m) return [0, 89, 255];
    const n = parseInt(m[1], 16);
    return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
}
const accentDark = computed(() => {
    const [r, g, b] = hexRgb(accent.value).map((c) => Math.round(c * 0.72));
    return `rgb(${r} ${g} ${b})`;
});
const bandTint = computed(() => {
    const [r, g, b] = hexRgb(accent.value);
    return `rgba(${r},${g},${b},0.045)`;
});

/** Wrap a category label into up to two ~11-char lines, ellipsized. */
function wrapLabel(full: string): [string, string] {
    const words = full.trim().split(/\s+/);
    const fit = (s: string) => (s.length <= 11 ? s : s.slice(0, 10) + '…');
    let l1 = fit(words[0] ?? '');
    let l2 = '';
    for (const w of words.slice(1)) {
        if (!l1.endsWith('…') && l2 === '' && `${l1} ${w}`.length <= 11) {
            l1 = `${l1} ${w}`;
        } else if (l2 === '') {
            l2 = fit(w);
        } else if (!l2.endsWith('…') && `${l2} ${w}`.length <= 11) {
            l2 = `${l2} ${w}`;
        } else {
            if (!l2.endsWith('…')) l2 += '…';
            break;
        }
    }
    return [l1, l2];
}

/** Smallest "nice" step so 3 divisions cover the max (450 for a 412 peak). */
function niceStep(rawStep: number): number {
    const pow = Math.pow(10, Math.floor(Math.log10(Math.max(1, rawStep))));
    for (const m of [1, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10]) {
        if (m * pow >= rawStep) return m * pow;
    }
    return 10 * pow;
}

// Responsive viewBox: when the card has an explicit height, the plot's height
// unit tracks the container's real aspect ratio so the chart FILLS the card
// (no letterbox, no distortion — the geometry re-lays-out at the new scale).
const plotEl = ref<HTMLElement | null>(null);
const { width: plotW, height: plotH } = useElementSize(plotEl);
const VW = 1000;
const LABEL_BAND = 92; // viewBox units reserved below the plot for x labels
const vh = computed(() => {
    if (!props.fitHeight || plotW.value === 0 || plotH.value === 0) return 520;
    return Math.round(
        Math.min(900, Math.max(320, (VW * plotH.value) / plotW.value)),
    );
});

const model = computed(() => {
    const data = props.items
        .filter((d) => Number.isFinite(d.value) && d.value > 0)
        .slice()
        .sort((a, b) => b.value - a.value);
    const n = data.length;
    if (n === 0) return null;

    const total = data.reduce((s, d) => s + d.value, 0);
    let run = 0;
    const cumPct = data.map((d) => ((run += d.value) / total) * 100);

    let cross = cumPct.findIndex((p) => p >= props.threshold);
    if (cross < 0) cross = n - 1;
    const vitalCount = cross + 1;

    // Geometry: plot spans x 52..948, y 26..(vh - label band); labels below.
    const x0 = 52;
    const x1 = 948;
    const y0 = 26;
    const y1 = vh.value - LABEL_BAND;
    const plotArea = y1 - y0;
    const band = (x1 - x0) / n;
    const barW = Math.min(32, band * 0.52);
    const step = niceStep(Math.max(1, ...data.map((d) => d.value)) / 3);
    const yMax = step * 3;
    const yv = (v: number) => y1 - (v / yMax) * plotArea;
    const yp = (p: number) => y1 - (p / 100) * plotArea;
    const cxOf = (i: number) => x0 + band * (i + 0.5);
    const labelStride = band < 34 ? Math.ceil(34 / band) : 1;

    const bars = data.map((d, i) => {
        const [l1, l2] = wrapLabel(d.label);
        return {
            i,
            full: d.label,
            value: d.value,
            vital: i < vitalCount,
            cx: cxOf(i),
            x: cxOf(i) - barW / 2,
            y: yv(d.value),
            w: barW,
            h: Math.max(0, y1 - yv(d.value)),
            ptY: yp(cumPct[i]),
            cum: cumPct[i],
            share: (d.value / total) * 100,
            l1,
            l2,
            showLabel: i % labelStride === 0,
            hitX: x0 + band * i,
        };
    });

    return {
        n,
        total,
        vitalCount,
        x0,
        x1,
        y0,
        y1,
        band,
        bars,
        leftTicks: [0, 1, 2, 3].map((k) => ({
            y: yv(k * step),
            label: nf.value.format(k * step),
        })),
        rightTicks: [0, 50, 100].map((p) => ({ y: yp(p), label: `${p}%` })),
        thresholdY: yp(props.threshold),
        dividerX: x0 + band * vitalCount,
        linePoints: bars
            .map((b) => `${b.cx.toFixed(1)},${b.ptY.toFixed(1)}`)
            .join(' '),
    };
});

const active = computed(() =>
    model.value !== null && hover.value !== null
        ? (model.value.bars[hover.value] ?? null)
        : null,
);

const badgeText = computed(() =>
    model.value === null
        ? ''
        : `${model.value.vitalCount} ${props.categoryNoun} = ${props.threshold}% del volumen`,
);

const footnoteText = computed(() => {
    if (props.footnote === false) return null;
    return (
        props.footnote ??
        'Pasa el cursor sobre cada barra para ver el nombre completo. Ordenado de mayor a menor.'
    );
});
</script>

<template>
    <div
        v-if="model"
        ref="rootEl"
        class="pareto-chart"
        :class="fitHeight ? 'flex h-full flex-col' : ''"
    >
        <!-- legend + insight badge -->
        <div class="mb-1 flex flex-wrap items-center justify-between gap-3">
            <div
                class="flex flex-wrap items-center gap-5 text-[12px] opacity-80"
            >
                <span class="flex items-center gap-1.5">
                    <span
                        class="inline-block h-[3px] w-[18px] rounded-xs"
                        :style="{ background: lineColor }"
                    />
                    % acumulado <span class="opacity-60">(eje der.)</span>
                </span>
                <span class="flex items-center gap-1.5">
                    <span
                        class="inline-block w-[18px] border-t-2 border-dashed opacity-50"
                    />
                    Umbral {{ threshold }}%
                </span>
            </div>
            <div
                v-if="showBadge"
                class="flex items-center gap-2.5 rounded-sp-sm border border-medium px-3 py-2"
                :style="{ background: bandTint }"
            >
                <svg
                    width="17"
                    height="17"
                    viewBox="0 0 24 24"
                    fill="none"
                    :stroke="accent"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    class="shrink-0"
                >
                    <path d="M3 3v18h18" />
                    <path d="M7 14l4-4 3 3 5-6" />
                </svg>
                <span class="leading-tight">
                    <span class="block text-[13px] font-semibold">{{
                        badgeText
                    }}</span>
                    <span class="block text-[11px] opacity-60"
                        >{{ nf.format(model.total) }}
                        {{ measureLabel.toLowerCase() }} en total</span
                    >
                </span>
            </div>
        </div>

        <div
            ref="plotEl"
            class="relative"
            :class="fitHeight ? 'min-h-0 flex-1' : ''"
            @mousemove="onMove"
            @mouseleave="hover = null"
        >
            <svg
                :viewBox="`0 0 1000 ${vh}`"
                preserveAspectRatio="xMidYMid meet"
                class="block w-full overflow-visible"
                :class="fitHeight ? 'h-full' : 'h-auto'"
            >
                <!-- vital band + divider -->
                <rect
                    :x="model.x0"
                    :y="model.y0"
                    :width="model.dividerX - model.x0"
                    :height="model.y1 - model.y0"
                    :fill="bandTint"
                    rx="3"
                />
                <line
                    :x1="model.dividerX"
                    :y1="model.y0"
                    :x2="model.dividerX"
                    :y2="model.y1"
                    stroke="currentColor"
                    stroke-opacity="0.25"
                    stroke-width="1"
                    stroke-dasharray="4 4"
                />

                <!-- gridlines + left/right axis labels -->
                <g v-for="(tk, k) in model.leftTicks" :key="'gl' + k">
                    <line
                        :x1="model.x0"
                        :y1="tk.y"
                        :x2="model.x1"
                        :y2="tk.y"
                        stroke="currentColor"
                        stroke-opacity="0.08"
                        stroke-width="1"
                    />
                    <text
                        :x="model.x0 - 10"
                        :y="tk.y + 4"
                        text-anchor="end"
                        fill="currentColor"
                        fill-opacity="0.45"
                        font-size="12"
                    >
                        {{ tk.label }}
                    </text>
                </g>
                <text
                    v-for="(tk, k) in model.rightTicks"
                    :key="'gr' + k"
                    :x="model.x1 + 10"
                    :y="tk.y + 4"
                    text-anchor="start"
                    fill="currentColor"
                    fill-opacity="0.45"
                    font-size="12"
                >
                    {{ tk.label }}
                </text>

                <!-- bars -->
                <rect
                    v-for="b in model.bars"
                    :key="'b' + b.i"
                    :x="b.x"
                    :y="b.y"
                    :width="b.w"
                    :height="b.h"
                    rx="4"
                    :fill="
                        b.vital
                            ? hover === b.i
                                ? accentDark
                                : accent
                            : '#cbd5ec'
                    "
                    :fill-opacity="b.vital ? 1 : hover === b.i ? 1 : 0.85"
                    style="transition: fill 0.18s ease"
                />

                <!-- value labels -->
                <template v-if="showValues">
                    <text
                        v-for="b in model.bars"
                        :key="'v' + b.i"
                        :x="b.cx"
                        :y="b.y - 8"
                        text-anchor="middle"
                        font-size="11.5"
                        font-weight="600"
                        :fill="b.vital ? accentDark : 'currentColor'"
                        :fill-opacity="b.vital ? 1 : 0.45"
                    >
                        {{ nf.format(b.value) }}
                    </text>
                </template>

                <!-- threshold line -->
                <line
                    :x1="model.x0"
                    :y1="model.thresholdY"
                    :x2="model.x1"
                    :y2="model.thresholdY"
                    stroke="currentColor"
                    stroke-opacity="0.3"
                    stroke-width="1.5"
                    stroke-dasharray="6 5"
                />

                <!-- cumulative line + points + % labels -->
                <polyline
                    :points="model.linePoints"
                    pathLength="100"
                    fill="none"
                    :stroke="lineColor"
                    stroke-width="2.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    class="pareto-line"
                />
                <g v-for="b in model.bars" :key="'p' + b.i">
                    <circle
                        :cx="b.cx"
                        :cy="b.ptY"
                        :r="hover === b.i ? 5 : 3.5"
                        fill="#ffffff"
                        :stroke="lineColor"
                        stroke-width="2.5"
                        style="transition: r 0.15s ease"
                    />
                    <text
                        v-if="b.showLabel"
                        :x="b.cx"
                        :y="b.ptY - 10"
                        text-anchor="middle"
                        font-size="10.5"
                        font-weight="600"
                        :fill="lineColor"
                    >
                        {{ fmtPct(b.cum) }}
                    </text>
                </g>

                <!-- two-line category labels -->
                <g v-for="b in model.bars" :key="'x' + b.i">
                    <template v-if="b.showLabel">
                        <text
                            :x="b.cx"
                            :y="model.y1 + 22"
                            text-anchor="middle"
                            font-size="10"
                            :font-weight="
                                hover === b.i ? 600 : b.vital ? 500 : 400
                            "
                            fill="currentColor"
                            :fill-opacity="
                                hover === b.i ? 0.95 : b.vital ? 0.75 : 0.45
                            "
                        >
                            {{ b.l1 }}
                        </text>
                        <text
                            v-if="b.l2"
                            :x="b.cx"
                            :y="model.y1 + 34"
                            text-anchor="middle"
                            font-size="10"
                            :font-weight="
                                hover === b.i ? 600 : b.vital ? 500 : 400
                            "
                            fill="currentColor"
                            :fill-opacity="
                                hover === b.i ? 0.95 : b.vital ? 0.75 : 0.45
                            "
                        >
                            {{ b.l2 }}
                        </text>
                    </template>
                </g>

                <!-- hover / click hit areas -->
                <rect
                    v-for="b in model.bars"
                    :key="'h' + b.i"
                    :x="b.hitX"
                    :y="model.y0"
                    :width="model.band"
                    :height="model.y1 - model.y0 + 36"
                    fill="transparent"
                    class="cursor-pointer"
                    @mouseenter="hover = b.i"
                    @click="emit('select', b.full)"
                />
            </svg>

            <!-- tooltip: the full label + the three numbers that matter -->
            <div
                v-if="active"
                class="pointer-events-none absolute z-20 max-w-72 rounded-sp-sm border border-medium bg-navy-elevated px-3 py-2 text-[11px] shadow-xl"
                :style="{ left: mouse.x + 14 + 'px', top: mouse.y + 14 + 'px' }"
            >
                <p class="mb-1 font-semibold">{{ active.full }}</p>
                <p class="flex items-center gap-1.5 tabular-nums opacity-80">
                    <span
                        class="size-2 rounded-xs"
                        :style="{
                            background: active.vital ? accent : '#cbd5ec',
                        }"
                    />
                    {{ measureLabel }}: {{ nf.format(active.value) }} ({{
                        fmtPct(active.share)
                    }})
                </p>
                <p class="flex items-center gap-1.5 tabular-nums opacity-80">
                    <span
                        class="size-2 rounded-xs"
                        :style="{ background: lineColor }"
                    />
                    % acumulado: {{ fmtPct(active.cum) }}
                </p>
            </div>
        </div>

        <p v-if="footnoteText" class="mt-1.5 text-[11.5px] opacity-45">
            {{ footnoteText }}
        </p>
    </div>
</template>

<style scoped>
@keyframes pareto-line-draw {
    to {
        stroke-dashoffset: 0;
    }
}
.pareto-line {
    stroke-dasharray: 100;
    stroke-dashoffset: 100;
    animation: pareto-line-draw 1.1s ease 0.3s forwards;
}
</style>
