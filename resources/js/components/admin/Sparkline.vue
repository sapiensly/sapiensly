<script setup lang="ts">
import { computed, useId } from 'vue';

/**
 * Inline-SVG sparkline. Smoothed via Catmull-Rom → cubic Bézier, filled with
 * a vertical gradient under the curve, and bloomed with a Gaussian-blur glow
 * on the stroke. Tint drives every colour channel.
 */
interface Props {
    series: number[];
    tint?: string;
    width?: number;
    height?: number;
    /** Stroke weight. Glow scales with it. */
    strokeWidth?: number;
}

const props = withDefaults(defineProps<Props>(), {
    tint: 'var(--sp-accent-blue)',
    width: 240,
    height: 60,
    strokeWidth: 2,
});

const uid = useId();
const gradId = computed(() => `spark-grad-${uid}`);
const glowId = computed(() => `spark-glow-${uid}`);

const geometry = computed(() => {
    const points = props.series;
    if (points.length === 0) {
        return { path: '', area: '', end: null as { x: number; y: number } | null };
    }
    const min = Math.min(...points);
    const max = Math.max(...points);
    const range = max - min || 1;
    const stepX = points.length > 1 ? props.width / (points.length - 1) : 0;
    // Keep curve slightly off the bottom/top edges so the glow doesn't clip.
    const pad = props.strokeWidth;
    const innerHeight = props.height - pad * 2;

    const coords = points.map((v, i) => {
        const x = i * stepX;
        const y = pad + (innerHeight - ((v - min) / range) * innerHeight);
        return { x, y };
    });

    // Smoothed cubic bezier path — uses the Catmull-Rom control-point derivation
    // with a tension of 0.5 so the curve tracks the data without overshoot.
    const smooth = (pts: { x: number; y: number }[]) => {
        if (pts.length < 2) return '';
        if (pts.length === 2) return `M ${pts[0].x} ${pts[0].y} L ${pts[1].x} ${pts[1].y}`;

        const d: string[] = [`M ${pts[0].x.toFixed(2)} ${pts[0].y.toFixed(2)}`];
        for (let i = 0; i < pts.length - 1; i++) {
            const p0 = pts[i - 1] ?? pts[i];
            const p1 = pts[i];
            const p2 = pts[i + 1];
            const p3 = pts[i + 2] ?? p2;
            const c1x = p1.x + (p2.x - p0.x) / 6;
            const c1y = p1.y + (p2.y - p0.y) / 6;
            const c2x = p2.x - (p3.x - p1.x) / 6;
            const c2y = p2.y - (p3.y - p1.y) / 6;
            d.push(
                `C ${c1x.toFixed(2)} ${c1y.toFixed(2)}, ${c2x.toFixed(2)} ${c2y.toFixed(2)}, ${p2.x.toFixed(2)} ${p2.y.toFixed(2)}`,
            );
        }
        return d.join(' ');
    };

    const path = smooth(coords);
    const area = `${path} L ${props.width.toFixed(2)} ${props.height} L 0 ${props.height} Z`;

    return { path, area, end: coords[coords.length - 1] };
});
</script>

<template>
    <svg
        :viewBox="`0 0 ${width} ${height}`"
        preserveAspectRatio="none"
        class="h-full w-full overflow-visible"
        aria-hidden="true"
    >
        <defs>
            <linearGradient :id="gradId" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" :stop-color="tint" stop-opacity="0.45" />
                <stop offset="60%" :stop-color="tint" stop-opacity="0.08" />
                <stop offset="100%" :stop-color="tint" stop-opacity="0" />
            </linearGradient>

            <!--
              Subtle bloom around the stroke. stdDeviation tuned so the glow
              reads on the dark navy surface without smearing the line.
            -->
            <filter :id="glowId" x="-20%" y="-50%" width="140%" height="200%">
                <feGaussianBlur stdDeviation="2.4" result="blur" />
                <feMerge>
                    <feMergeNode in="blur" />
                    <feMergeNode in="SourceGraphic" />
                </feMerge>
            </filter>
        </defs>

        <path
            v-if="geometry.area"
            :d="geometry.area"
            :fill="`url(#${gradId})`"
        />
        <path
            v-if="geometry.path"
            :d="geometry.path"
            :stroke="tint"
            :stroke-width="strokeWidth"
            stroke-linecap="round"
            stroke-linejoin="round"
            fill="none"
            :filter="`url(#${glowId})`"
        />
        <circle
            v-if="geometry.end"
            :cx="geometry.end.x"
            :cy="geometry.end.y"
            r="2.5"
            :fill="tint"
            :filter="`url(#${glowId})`"
        />
    </svg>
</template>
