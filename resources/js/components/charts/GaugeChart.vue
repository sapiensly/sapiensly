<script setup lang="ts">
/**
 * Target gauge — the Claude-design read of "how close to the goal":
 * a 270° horseshoe with a gradient arc, a glowing tip dot at the current
 * value, a tick where the TARGET sits, status pill (En meta / Bajo meta)
 * and the distance to target in points. Self-contained: value + target in,
 * palette accent resolved from CSS vars.
 */
import { resolveCssColor } from '@/lib/resolveCssColor';
import { computed, onMounted, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        label: string;
        value: number;
        target: number;
        format?: 'percentage' | 'number';
        accent?: string;
        locale?: string;
    }>(),
    {
        format: 'number',
        accent: '#0059ff',
        locale: 'es-MX',
    },
);

const uid = `gg${Math.random().toString(36).slice(2, 8)}`;

const rootEl = ref<HTMLElement | null>(null);
const accent = ref(props.accent);
const syncAccent = () => {
    accent.value = resolveCssColor(props.accent, rootEl.value);
};
onMounted(syncAccent);
watch(() => props.accent, syncAccent);

function hexRgb(hex: string): [number, number, number] {
    const m = /^#?([0-9a-f]{6})$/i.exec(hex.trim());
    if (!m) return [0, 89, 255];
    const n = parseInt(m[1], 16);
    return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
}
/** Light stop of the gradient: the accent mixed 55% toward white. */
const accentLight = computed(() => {
    const [r, g, b] = hexRgb(accent.value).map((c) =>
        Math.round(c + (255 - c) * 0.55),
    );
    return `rgb(${r} ${g} ${b})`;
});

const es = computed(() => !props.locale?.startsWith('en'));
const nf = computed(() => new Intl.NumberFormat(props.locale));

// Percent measures usually arrive on the 0-100 scale (fcr_pct 72.6, target
// 80): the axis is 0-100 and the target is a tick on it. Other units get an
// axis with the target at 80% of the sweep.
const isPct = computed(() => props.format === 'percentage');
const axisMax = computed(() =>
    isPct.value ? 100 : Math.max(1, props.target) * 1.25,
);
const f = computed(() =>
    Math.max(0, Math.min(1, props.value / axisMax.value)),
);
const tf = computed(() =>
    Math.max(0, Math.min(1, props.target / axisMax.value)),
);
const onTarget = computed(() => props.value >= props.target);

const valueLabel = computed(() =>
    isPct.value
        ? `${(Math.round(props.value * 10) / 10).toLocaleString(props.locale)}%`
        : nf.value.format(props.value),
);
const targetLabel = computed(() =>
    isPct.value ? `${nf.value.format(props.target)}%` : nf.value.format(props.target),
);
const statusColor = computed(() => (onTarget.value ? '#1f9d61' : '#e08a1e'));
const statusBg = computed(() => (onTarget.value ? '#e7f6ee' : '#fdf3e3'));
const statusLabel = computed(() =>
    onTarget.value
        ? es.value
            ? 'En meta'
            : 'On target'
        : es.value
          ? 'Bajo meta'
          : 'Below target',
);
const gapLabel = computed(() => {
    const gap = Math.abs(props.target - props.value);
    const pts = (Math.round(gap * 10) / 10).toLocaleString(props.locale);
    if (onTarget.value) {
        return es.value ? `${pts} pts sobre meta` : `${pts} pts above target`;
    }
    return es.value ? `${pts} pts a la meta` : `${pts} pts to target`;
});

// 270° horseshoe: 225° (bottom-left) sweeping clockwise to -45°.
const CX = 150;
const CY = 148;
const R = 112;
const SW = 16;
const rad = (frac: number) => ((225 - 270 * frac) * Math.PI) / 180;
const pt = (frac: number, rr: number): [number, number] => [
    CX + rr * Math.cos(rad(frac)),
    CY - rr * Math.sin(rad(frac)),
];
const arcPath = computed(() => {
    const [sx, sy] = pt(0, R);
    const [ex, ey] = pt(1, R);
    return `M ${sx.toFixed(2)} ${sy.toFixed(2)} A ${R} ${R} 0 1 1 ${ex.toFixed(2)} ${ey.toFixed(2)}`;
});
const tip = computed(() => pt(f.value, R));
const tickA = computed(() => pt(tf.value, R - SW / 2 - 2));
const tickB = computed(() => pt(tf.value, R + SW / 2 + 2));
</script>

<template>
    <div ref="rootEl" class="gauge-chart flex h-full min-h-0 flex-col">
        <!-- header: icon badge + eyebrow/label, status pill -->
        <div class="mb-0.5 flex items-center justify-between gap-3">
            <div class="flex min-w-0 items-center gap-2.5">
                <span class="min-w-0">
                    <span
                        class="block text-[10px] font-semibold tracking-[1.4px] uppercase opacity-50"
                        >{{ es ? 'Meta' : 'Target' }}</span
                    >
                    <span class="block truncate text-[15px] font-semibold">{{
                        label
                    }}</span>
                </span>
            </div>
            <span
                class="flex shrink-0 items-center gap-1.5 rounded-pill px-3 py-1.5 text-xs font-semibold whitespace-nowrap"
                :style="{ background: statusBg, color: statusColor }"
            >
                <span
                    class="inline-block size-[7px] rounded-full"
                    :style="{ background: statusColor }"
                />
                {{ statusLabel }}
            </span>
        </div>

        <!-- horseshoe -->
        <div class="relative my-auto py-1.5">
            <svg
                viewBox="0 0 300 250"
                class="block w-full"
                style="overflow: visible"
            >
                <defs>
                    <linearGradient :id="uid" x1="0" y1="1" x2="1" y2="0">
                        <stop offset="0" :stop-color="accentLight" />
                        <stop offset="1" :stop-color="accent" />
                    </linearGradient>
                    <filter
                        :id="uid + 't'"
                        x="-60%"
                        y="-60%"
                        width="220%"
                        height="220%"
                    >
                        <feDropShadow
                            dx="0"
                            dy="0"
                            stdDeviation="3.5"
                            :flood-color="accent"
                            flood-opacity="0.55"
                        />
                    </filter>
                </defs>
                <path
                    :d="arcPath"
                    fill="none"
                    stroke="currentColor"
                    stroke-opacity="0.08"
                    :stroke-width="SW"
                    stroke-linecap="round"
                />
                <path
                    :d="arcPath"
                    fill="none"
                    :stroke="`url(#${uid})`"
                    :stroke-width="SW"
                    stroke-linecap="round"
                    pathLength="100"
                    :stroke-dasharray="`${(f * 100).toFixed(2)} 100`"
                    class="gauge-arc"
                />
                <line
                    :x1="tickA[0]"
                    :y1="tickA[1]"
                    :x2="tickB[0]"
                    :y2="tickB[1]"
                    stroke="currentColor"
                    stroke-width="3"
                    stroke-linecap="round"
                />
                <circle
                    :cx="tip[0]"
                    :cy="tip[1]"
                    :r="SW / 2 + 2.5"
                    :fill="accent"
                    :filter="`url(#${uid}t)`"
                />
            </svg>
            <div
                class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center pt-6"
            >
                <span class="text-2xl leading-none font-bold tracking-[-1px]">{{
                    valueLabel
                }}</span>
                <span
                    class="mt-2 flex items-center gap-1.5 text-[12.5px] font-medium opacity-55"
                >
                    <span
                        class="inline-block size-2 rounded-[2px] bg-current"
                    />
                    meta {{ targetLabel }}
                </span>
            </div>
        </div>

        <!-- footer: legend + distance to target -->
        <div
            class="mt-2 flex items-center justify-between border-t border-medium pt-3.5"
        >
            <span class="flex items-center gap-2 text-[12.5px] opacity-60">
                <span
                    class="inline-block h-[5px] w-[22px] rounded-[3px]"
                    :style="{
                        background: `linear-gradient(90deg, ${accentLight}, ${accent})`,
                    }"
                />
                {{ es ? 'Resultado actual' : 'Current result' }}
            </span>
            <span
                class="flex items-center gap-1 text-[12.5px] font-semibold"
                :style="{ color: statusColor }"
            >
                {{ onTarget ? '▲' : '▼' }}
                <span>{{ gapLabel }}</span>
            </span>
        </div>
    </div>
</template>
