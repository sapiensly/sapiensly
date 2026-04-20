<script setup lang="ts">
import { computed } from 'vue';

/**
 * The main usage chart on the dashboard. Inline SVG with gridlines, area
 * fill, stroke, and an end dot. Two series overlaid (chat + embeddings)
 * with separate tints. No chart lib.
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
    gridlines?: number;
}

const props = withDefaults(defineProps<Props>(), {
    width: 720,
    height: 220,
    gridlines: 4,
});

const maxValue = computed(() =>
    Math.max(1, ...props.series.flatMap((s) => s.points)),
);

function toPath(points: number[]): { path: string; area: string; end: { x: number; y: number } | null } {
    if (points.length === 0) return { path: '', area: '', end: null };
    const stepX = points.length > 1 ? props.width / (points.length - 1) : 0;
    const coords = points.map((v, i) => {
        const x = i * stepX;
        const y = props.height - (v / maxValue.value) * (props.height - 20) - 10;
        return { x, y };
    });
    const path = coords
        .map((p, i) => `${i === 0 ? 'M' : 'L'} ${p.x.toFixed(2)} ${p.y.toFixed(2)}`)
        .join(' ');
    const area = `${path} L ${props.width.toFixed(2)} ${props.height} L 0 ${props.height} Z`;
    return { path, area, end: coords[coords.length - 1] };
}

const gridYs = computed(() => {
    const lines: number[] = [];
    for (let i = 1; i <= props.gridlines; i++) {
        lines.push((props.height / (props.gridlines + 1)) * i);
    }
    return lines;
});
</script>

<template>
    <div class="w-full">
        <svg
            :viewBox="`0 0 ${width} ${height}`"
            preserveAspectRatio="none"
            class="h-56 w-full overflow-visible"
            aria-hidden="true"
        >
            <g stroke="var(--sp-border-soft)" stroke-dasharray="2 4">
                <line
                    v-for="(y, i) in gridYs"
                    :key="`grid-${i}`"
                    x1="0"
                    :y1="y"
                    :x2="width"
                    :y2="y"
                />
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

        <ul class="mt-3 flex flex-wrap gap-4 text-xs text-ink-muted">
            <li
                v-for="s in series"
                :key="`legend-${s.label}`"
                class="flex items-center gap-1.5"
            >
                <span
                    class="inline-block size-2 rounded-pill"
                    :style="{ backgroundColor: s.tint }"
                />
                {{ s.label }}
            </li>
        </ul>
    </div>
</template>
