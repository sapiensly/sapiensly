<script setup lang="ts">
/**
 * Treemap — the Claude-design read of a many-category part-of-whole:
 * squarified cells rendered as DIVs (labels WRAP instead of clipping),
 * rounded with an even gutter, the full label top-left, value + share%
 * pinned bottom-left, font tiers by cell area, full text on the tooltip.
 * Self-contained: [{label, value}] in, palette colors as props.
 */
import { resolveCssColor } from '@/lib/resolveCssColor';
import { computed, onMounted, ref, watch } from 'vue';

export interface TreemapItem {
    label: string;
    value: number;
}

const props = withDefaults(
    defineProps<{
        items: TreemapItem[];
        colors?: string[];
        locale?: string;
        maxCells?: number;
        clickable?: boolean;
    }>(),
    {
        colors: () => [
            '#2f7bef',
            '#f59e0b',
            '#0fb5c9',
            '#12a06d',
            '#8b5cf6',
            '#ec4899',
        ],
        locale: 'es-MX',
        maxCells: 12,
        clickable: false,
    },
);

const emit = defineEmits<{ (e: 'select', label: string): void }>();

const nf = computed(() => new Intl.NumberFormat(props.locale));

// Palette entries often arrive as `var(--sp-chart-N, #hex)` — resolve them
// against this element so cells run the org palette.
const rootEl = ref<HTMLElement | null>(null);
const palette = ref<string[]>(props.colors);
const syncPalette = () => {
    palette.value = props.colors.map((c) => resolveCssColor(c, rootEl.value));
};
onMounted(syncPalette);
watch(() => props.colors, syncPalette);

const cells = computed(() => {
    const data = props.items
        .filter((d) => Number.isFinite(d.value) && d.value > 0)
        .slice()
        .sort((a, b) => b.value - a.value)
        .slice(0, props.maxCells);
    const total = data.reduce((s, d) => s + d.value, 0);
    if (data.length === 0 || total <= 0) return [];

    // Squarified layout on a 100×100 unit square (the container's aspect
    // ratio stretches it uniformly; areas stay proportional).
    type Rect = {
        x: number;
        y: number;
        w: number;
        h: number;
        label: string;
        value: number;
        share: number;
        color: string;
    };
    const rects: Rect[] = [];
    let x = 0;
    let y = 0;
    let w = 100;
    let h = 100;
    let i = 0;
    let remaining = total;
    while (i < data.length) {
        const horizontal = w >= h;
        const side = horizontal ? h : w;
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
        row.forEach((d) => {
            const cell = (d.value / rowSum) * side;
            const idx = rects.length;
            rects.push({
                x: horizontal ? x : x + off,
                y: horizontal ? y + off : y,
                w: horizontal ? rowLen : cell,
                h: horizontal ? cell : rowLen,
                label: d.label,
                value: d.value,
                share: (d.value / total) * 100,
                color: palette.value[idx % palette.value.length],
            });
            off += cell;
        });
        if (horizontal) {
            x += rowLen;
            w -= rowLen;
        } else {
            y += rowLen;
            h -= rowLen;
        }
        remaining -= rowSum;
        i += row.length;
    }

    return rects.map((r, idx) => {
        const area = (r.w * r.h) / 100; // % of the whole
        return {
            ...r,
            idx,
            // Font tiers by area: big cells headline, small cells whisper.
            labelClass:
                area >= 10
                    ? 'text-[21px] leading-[1.15]'
                    : area >= 5
                      ? 'text-[16px] leading-[1.2]'
                      : area >= 2.5
                        ? 'text-[12px] leading-[1.25]'
                        : 'hidden',
            valueClass:
                area >= 5
                    ? 'text-[17px]'
                    : area >= 2
                      ? 'text-[13px]'
                      : 'text-[11px]',
            shareClass: area >= 2 ? '' : 'hidden',
            clampClass: area >= 10 ? 'line-clamp-3' : 'line-clamp-2',
        };
    });
});
</script>

<template>
    <div
        ref="rootEl"
        class="relative w-full"
        style="aspect-ratio: 2 / 1; min-height: 260px"
    >
        <div
            v-for="cell in cells"
            :key="cell.idx"
            class="absolute"
            :style="{
                left: cell.x + '%',
                top: cell.y + '%',
                width: cell.w + '%',
                height: cell.h + '%',
            }"
        >
            <div
                class="absolute inset-[4px] flex flex-col justify-between overflow-hidden rounded-xl p-3.5 text-white transition-[filter] duration-150 sm:p-4"
                :class="clickable ? 'cursor-pointer hover:brightness-95' : ''"
                :style="{ background: cell.color }"
                :title="`${cell.label} — ${nf.format(cell.value)} (${cell.share.toFixed(1)}%)`"
                @click="clickable && emit('select', cell.label)"
            >
                <p
                    class="font-bold tracking-[-0.2px]"
                    :class="[cell.labelClass, cell.clampClass]"
                >
                    {{ cell.label }}
                </p>
                <p
                    class="flex items-baseline gap-1.5 leading-none font-bold tabular-nums"
                    :class="cell.valueClass"
                >
                    {{ nf.format(cell.value) }}
                    <span
                        class="text-[11px] font-medium opacity-75"
                        :class="cell.shareClass"
                        >{{ cell.share.toFixed(1) }}%</span
                    >
                </p>
            </div>
        </div>
    </div>
</template>
