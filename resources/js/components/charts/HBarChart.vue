<script setup lang="ts">
/**
 * Ranked horizontal bars — the Claude-design read of a categorical breakdown,
 * self-contained so any surface can drop it in with just [{label, value}]:
 *
 *  - header stats (total + category count) with a hairline divider
 *  - rows: rank (01…), ellipsized label, a thin track whose fill length is
 *    value/max and whose color runs a SEQUENTIAL ramp of the accent hue
 *    (darker = bigger), value + share%
 *  - hover: row tint, accent label, and a cumulative-% pill riding the track
 *  - click-through per row for drill-downs
 */
import { computed, ref } from 'vue';

export interface HBarItem {
    label: string;
    value: number;
}

const props = withDefaults(
    defineProps<{
        items: HBarItem[];
        accent?: string;
        measureLabel?: string;
        categoryNoun?: string;
        locale?: string;
        showHeaderStats?: boolean;
        clickable?: boolean;
    }>(),
    {
        accent: '#0059ff',
        measureLabel: 'Total',
        categoryNoun: 'categorías',
        locale: 'es-MX',
        showHeaderStats: true,
        clickable: false,
    },
);

const emit = defineEmits<{ (e: 'select', label: string): void }>();

const hover = ref<number | null>(null);
const nf = computed(() => new Intl.NumberFormat(props.locale));

/** Accent hex → [hue, sat%] so the sequential ramp follows the palette. */
const accentHs = computed<[number, number]>(() => {
    const m = /^#?([0-9a-f]{6})$/i.exec(props.accent.trim());
    if (!m) return [220, 92];
    const n = parseInt(m[1], 16);
    const r = ((n >> 16) & 255) / 255;
    const g = ((n >> 8) & 255) / 255;
    const b = (n & 255) / 255;
    const max = Math.max(r, g, b);
    const min = Math.min(r, g, b);
    const d = max - min;
    let h = 0;
    if (d > 0) {
        if (max === r) h = ((g - b) / d) % 6;
        else if (max === g) h = (b - r) / d + 2;
        else h = (r - g) / d + 4;
        h = Math.round(h * 60);
        if (h < 0) h += 360;
    }
    const l = (max + min) / 2;
    const s = d === 0 ? 0 : d / (1 - Math.abs(2 * l - 1));
    return [h, Math.round(Math.max(0.5, Math.min(1, s)) * 100)];
});

const model = computed(() => {
    const data = props.items
        .filter((d) => Number.isFinite(d.value) && d.value > 0)
        .slice()
        .sort((a, b) => b.value - a.value);
    if (data.length === 0) return null;

    const max = Math.max(...data.map((d) => d.value));
    const min = Math.min(...data.map((d) => d.value));
    const total = data.reduce((s, d) => s + d.value, 0);
    const span = Math.max(max - min, 1);
    const [hue, sat] = accentHs.value;

    let running = 0;
    const rows = data.map((d, i) => {
        // 1 = biggest, 0 = smallest → darker for higher volume.
        const r = (d.value - min) / span;
        running += d.value;
        return {
            i,
            rank: String(i + 1).padStart(2, '0'),
            label: d.label,
            value: nf.value.format(d.value),
            share: ((d.value / total) * 100).toFixed(1) + '%',
            cum: 'acum. ' + ((running / total) * 100).toFixed(0) + '%',
            width: ((d.value / max) * 100).toFixed(1) + '%',
            color: `hsl(${hue}, ${Math.min(100, sat + r * 12)}%, ${60 - r * 12}%)`,
        };
    });

    return { rows, count: data.length, total: nf.value.format(total) };
});
</script>

<template>
    <div v-if="model" class="hbar-chart">
        <div
            v-if="showHeaderStats"
            class="mb-1 flex items-center justify-end gap-4 border-b border-medium pb-2"
        >
            <span class="text-right">
                <span class="block text-[19px] leading-none font-bold">{{
                    model.total
                }}</span>
                <span
                    class="mt-1 block text-[9.5px] font-semibold tracking-wider uppercase opacity-50"
                    >{{ measureLabel }}</span
                >
            </span>
            <span class="h-7 w-px bg-current opacity-15" />
            <span class="text-right">
                <span class="block text-[19px] leading-none font-bold">{{
                    model.count
                }}</span>
                <span
                    class="mt-1 block text-[9.5px] font-semibold tracking-wider uppercase opacity-50"
                    >{{ categoryNoun }}</span
                >
            </span>
        </div>

        <div class="mt-1">
            <div
                v-for="row in model.rows"
                :key="row.i"
                class="hbar-row grid grid-cols-[24px_minmax(90px,34%)_1fr_86px] items-center gap-3 rounded-sp-sm px-2 py-1"
                :class="clickable ? 'cursor-pointer' : ''"
                @mouseenter="hover = row.i"
                @mouseleave="hover = null"
                @click="clickable && emit('select', row.label)"
            >
                <span
                    class="text-right text-[11px] font-semibold tabular-nums transition-colors"
                    :class="hover === row.i ? '' : 'opacity-35'"
                    :style="hover === row.i ? { color: accent } : undefined"
                    >{{ row.rank }}</span
                >
                <span
                    class="truncate text-[12px] leading-tight font-medium transition-colors"
                    :style="hover === row.i ? { color: accent } : undefined"
                    :title="row.label"
                    >{{ row.label }}</span
                >
                <span
                    class="relative block h-2 rounded-pill bg-current/10"
                >
                    <span
                        class="hbar-fill block h-full rounded-pill"
                        :style="{
                            width: row.width,
                            background: row.color,
                            filter: hover === row.i ? 'brightness(0.9)' : undefined,
                            boxShadow:
                                hover === row.i
                                    ? '0 0 0 3px color-mix(in srgb, ' + accent + ' 14%, transparent)'
                                    : undefined,
                        }"
                    />
                    <span
                        class="absolute top-1/2 right-0 -mt-2 rounded-pill px-1.5 py-px text-[9.5px] font-semibold whitespace-nowrap transition-all duration-150"
                        :class="
                            hover === row.i
                                ? 'translate-x-0 opacity-100'
                                : '-translate-x-1 opacity-0'
                        "
                        :style="{
                            color: accent,
                            background: 'color-mix(in srgb, ' + accent + ' 12%, transparent)',
                        }"
                        >{{ row.cum }}</span
                    >
                </span>
                <span
                    class="flex items-baseline justify-end gap-1.5 text-right tabular-nums"
                >
                    <span class="text-[13px] font-semibold">{{
                        row.value
                    }}</span>
                    <span
                        class="min-w-[30px] text-right text-[10px] font-medium opacity-50"
                        >{{ row.share }}</span
                    >
                </span>
            </div>
        </div>
    </div>
</template>

<style scoped>
@keyframes hbar-in {
    from {
        transform: scaleX(0);
    }
    to {
        transform: scaleX(1);
    }
}
.hbar-fill {
    transform-origin: left;
    animation: hbar-in 620ms cubic-bezier(0.22, 1, 0.36, 1) both;
    transition:
        filter 140ms ease,
        box-shadow 140ms ease;
}
.hbar-row {
    transition: background 140ms ease;
}
.hbar-row:hover {
    background: color-mix(in srgb, currentColor 4%, transparent);
}
</style>
