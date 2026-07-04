<script setup lang="ts">
import { computed } from 'vue';
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
const stroke = computed(() => props.color ?? 'var(--sp-accent, #10b981)');
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
