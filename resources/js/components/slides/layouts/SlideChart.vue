<script setup lang="ts">
import type { DeckThemeTokens } from '@/lib/deck';
import {
    ArcElement,
    BarElement,
    CategoryScale,
    Chart as ChartJS,
    Legend,
    LinearScale,
    LineElement,
    PointElement,
    Tooltip,
} from 'chart.js';
import { computed } from 'vue';
import { Bar, Doughnut, Line } from 'vue-chartjs';

ChartJS.register(
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    BarElement,
    ArcElement,
    Tooltip,
    Legend,
);

interface Series {
    name: string;
    data: number[];
}

const props = defineProps<{
    title: string;
    chartType: 'bar' | 'line' | 'donut';
    labels: string[];
    series: Series[];
    takeaway?: string;
    tokens: DeckThemeTokens;
}>();

// The theme styles the chart; the manifest only supplies data. Fixed fonts
// assume the 1280×720 design canvas (the stage scales everything together).
const palette = computed(() => props.tokens.series);

const chartData = computed(() => {
    if (props.chartType === 'donut') {
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
        datasets: props.series.map((s, i) => ({
            label: s.name,
            data: s.data,
            backgroundColor:
                props.chartType === 'bar'
                    ? palette.value[i % palette.value.length]
                    : 'transparent',
            borderColor: palette.value[i % palette.value.length],
            borderWidth: props.chartType === 'line' ? 4 : 0,
            borderRadius: props.chartType === 'bar' ? 8 : 0,
            pointRadius: props.chartType === 'line' ? 5 : 0,
            pointBackgroundColor: palette.value[i % palette.value.length],
            tension: 0.35,
        })),
    };
});

const showLegend = computed(
    () => props.chartType === 'donut' || props.series.length > 1,
);

const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    animation: { duration: 300 },
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
    scales:
        props.chartType === 'donut'
            ? undefined
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
            <Line
                v-else-if="chartType === 'line'"
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
