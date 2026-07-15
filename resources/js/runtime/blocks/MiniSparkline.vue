<script setup lang="ts">
import { computed } from 'vue';
import { DEFAULT_ACCENT } from '../runtimeStyle';
import type { ObjectDef } from '../types/manifest';
import { resolveField } from '../types/manifest';
import {
    buildSparkPath,
    computeSparkSeries,
    type SparkAggregation,
    type SparkRow,
} from './sparkSeries';

// A lean, decoration-free sparkline for embedding INSIDE a KPI card (stat /
// metric_grid item): just the line + soft fill, no card, header or tooltip.
const props = defineProps<{
    rows: SparkRow[] | undefined;
    object: ObjectDef | undefined;
    xFieldId?: string;
    yFieldId?: string;
    aggregation?: SparkAggregation;
    color?: string;
}>();

const W = 96;
const H = 32;

const series = computed(() =>
    computeSparkSeries(
        props.rows ?? [],
        resolveField(props.object, props.xFieldId),
        resolveField(props.object, props.yFieldId),
        props.aggregation ?? 'count',
    ),
);

const path = computed(() => buildSparkPath(series.value, W, H));
// A spark is a CHART mark, so it takes the palette's first series colour — the
// same var the bars/lines beside it use. That is what makes it obey
// `palette_mode`: pick "Escala grises" in the builder and --sp-chart-1 becomes
// grey, so the spark greys out with the rest instead of staying brand-blue.
// --sp-chart-1 is always defined at runtime (the server derives the palette even
// when the manifest sets no accent); the accent and the platform default are
// belt-and-braces for surfaces that render a spark outside that scope.
const stroke = computed(
    () =>
        props.color ?? `var(--sp-chart-1, var(--sp-accent, ${DEFAULT_ACCENT}))`,
);
</script>

<template>
    <svg
        v-if="series.length > 1"
        :viewBox="`0 0 ${W} ${H}`"
        class="h-8 w-24 shrink-0 overflow-visible"
        preserveAspectRatio="none"
        aria-hidden="true"
    >
        <path :d="path.area" :fill="stroke" fill-opacity="0.1" stroke="none" />
        <path
            :d="path.line"
            fill="none"
            :stroke="stroke"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            vector-effect="non-scaling-stroke"
        />
    </svg>
</template>
