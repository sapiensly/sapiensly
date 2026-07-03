<script setup lang="ts">
import type { DeckThemeTokens } from '@/lib/deck';
import {
    ArcElement,
    BarElement,
    CategoryScale,
    Chart as ChartJS,
    Filler,
    Legend,
    LinearScale,
    LineElement,
    PointElement,
    RadialLinearScale,
    Tooltip,
} from 'chart.js';
import { computed } from 'vue';
import { Bar, Doughnut, Line, Pie, Radar } from 'vue-chartjs';

ChartJS.register(
    CategoryScale,
    LinearScale,
    RadialLinearScale,
    PointElement,
    LineElement,
    BarElement,
    ArcElement,
    Filler,
    Tooltip,
    Legend,
);

interface Series {
    name: string;
    data: number[];
}

const props = withDefaults(
    defineProps<{
        title: string;
        chartType: 'bar' | 'hbar' | 'line' | 'area' | 'pie' | 'donut' | 'radar';
        labels: string[];
        series: Series[];
        takeaway?: string;
        tokens: DeckThemeTokens;
        /** false in the PDF print page so the capture never races a tween. */
        animated?: boolean;
    }>(),
    { animated: true },
);

// The theme styles the chart; the manifest only supplies data. Fixed fonts
// assume the 1280×720 design canvas (the stage scales everything together).
const palette = computed(() => props.tokens.series);

// Coarse form groups: arc (one disc/ring), bar-ish, line-ish, radial.
const isArc = computed(
    () => props.chartType === 'donut' || props.chartType === 'pie',
);
const isBar = computed(
    () => props.chartType === 'bar' || props.chartType === 'hbar',
);
const isLine = computed(
    () => props.chartType === 'line' || props.chartType === 'area',
);

/** Translucent fill for area/radar bodies, from the solid palette hex. */
function softColor(hex: string): string {
    return `color-mix(in oklab, ${hex} 22%, transparent)`;
}

const chartData = computed(() => {
    if (isArc.value) {
        return {
            labels: props.labels,
            datasets: [
                {
                    data: props.series[0]?.data ?? [],
                    backgroundColor: props.labels.map(
                        (_, i) => palette.value[i % palette.value.length],
                    ),
                    borderWidth: 0,
                },
            ],
        };
    }
    return {
        labels: props.labels,
        datasets: props.series.map((s, i) => {
            const color = palette.value[i % palette.value.length];
            return {
                label: s.name,
                data: s.data,
                backgroundColor: isBar.value
                    ? color
                    : props.chartType === 'area' || props.chartType === 'radar'
                      ? softColor(color)
                      : 'transparent',
                fill: props.chartType === 'area' || props.chartType === 'radar',
                borderColor: color,
                borderWidth:
                    isLine.value || props.chartType === 'radar' ? 4 : 0,
                borderRadius: isBar.value ? 8 : 0,
                pointRadius: isLine.value
                    ? 5
                    : props.chartType === 'radar'
                      ? 4
                      : 0,
                pointBackgroundColor: color,
                tension: 0.35,
            };
        }),
    };
});

const showLegend = computed(() => isArc.value || props.series.length > 1);

// One options object shared across chart forms; chart.js's per-form generics
// reject the union (radar's r-scale vs cartesian x/y), so it is typed loosely.
const chartOptions = computed<any>(() => ({
    responsive: true,
    maintainAspectRatio: false,
    animation: props.animated ? { duration: 300 } : false,
    // hbar = horizontal bars: categories on Y, values on X.
    indexAxis: (props.chartType === 'hbar' ? 'y' : 'x') as 'x' | 'y',
    plugins: {
        legend: {
            display: showLegend.value,
            position: 'bottom' as const,
            labels: {
                color: props.tokens.muted,
                font: { size: 16 },
                boxWidth: 14,
                boxHeight: 14,
                borderRadius: 4,
                useBorderRadius: true,
                padding: 20,
            },
        },
        tooltip: { enabled: true },
    },
    scales: isArc.value
        ? undefined
        : props.chartType === 'radar'
          ? {
                r: {
                    grid: { color: props.tokens.line },
                    angleLines: { color: props.tokens.line },
                    pointLabels: {
                        color: props.tokens.muted,
                        font: { size: 15 },
                    },
                    ticks: {
                        color: props.tokens.subtle,
                        font: { size: 12 },
                        maxTicksLimit: 5,
                        backdropColor: 'transparent',
                    },
                },
            }
          : {
                x: {
                    grid: { display: false },
                    border: { color: props.tokens.line },
                    ticks: {
                        color: props.tokens.muted,
                        font: { size: 15 },
                    },
                },
                y: {
                    grid: { color: props.tokens.line },
                    border: { display: false },
                    ticks: {
                        color: props.tokens.subtle,
                        font: { size: 14 },
                        maxTicksLimit: 6,
                    },
                },
            },
}));
</script>

<template>
    <div class="slide-chart">
        <h2>{{ title }}</h2>
        <div class="canvas">
            <Doughnut
                v-if="chartType === 'donut'"
                :data="chartData"
                :options="chartOptions"
            />
            <Pie
                v-else-if="chartType === 'pie'"
                :data="chartData"
                :options="chartOptions"
            />
            <Radar
                v-else-if="chartType === 'radar'"
                :data="chartData"
                :options="chartOptions"
            />
            <Line
                v-else-if="chartType === 'line' || chartType === 'area'"
                :data="chartData"
                :options="chartOptions"
            />
            <Bar v-else :data="chartData" :options="chartOptions" />
        </div>
        <p v-if="takeaway" class="takeaway">
            <span class="rule" />
            {{ takeaway }}
        </p>
    </div>
</template>

<style scoped>
.slide-chart {
    display: flex;
    flex-direction: column;
    height: 100%;
    padding: 88px 96px 72px;
}
h2 {
    font-size: 40px;
    line-height: 1.15;
    font-weight: 700;
    letter-spacing: -0.01em;
    color: var(--deck-ink);
    margin-bottom: 36px;
}
.canvas {
    position: relative;
    flex: 1;
    min-height: 0;
}
.takeaway {
    display: flex;
    align-items: center;
    gap: 18px;
    margin-top: 32px;
    font-size: 22px;
    font-weight: 500;
    color: var(--deck-ink);
}
.rule {
    flex-shrink: 0;
    width: 36px;
    height: 5px;
    border-radius: 3px;
    background: var(--deck-accent);
}
</style>
