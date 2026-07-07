<script setup lang="ts">
import { computed } from 'vue';
import RuntimeIcon from '../RuntimeIcon.vue';
import type { ObjectDef, SparkSpec } from '../types/manifest';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';
import MiniSparkline from './MiniSparkline.vue';
import type { SparkRow } from './sparkSeries';
import { computeTrend } from './trend';

interface MetricItem {
    id: string;
    label: string;
    query: { object_id: string };
    aggregation: 'count' | 'sum' | 'avg' | 'min' | 'max';
    field_id?: string;
    format?: 'number' | 'currency' | 'percentage' | 'duration';
    icon?: string;
    delta_good?: 'up' | 'down';
    compare_label?: string;
    spark?: SparkSpec;
}

interface MetricGridBlock {
    id: string;
    type: 'metric_grid';
    columns?: number;
    items: MetricItem[];
}

interface MetricItemData {
    value: number;
    compare_value?: number;
    spark_rows?: SparkRow[];
}

const props = defineProps<{
    block: MetricGridBlock;
    data: { items: Record<string, MetricItemData> } | undefined;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

function sparkObjectFor(item: MetricItem): ObjectDef | undefined {
    return props.objects.find(
        (o) => o.id === item.spark?.data_source.object_id,
    );
}

function trendFor(item: MetricItem) {
    const entry = props.data?.items?.[item.id];
    return computeTrend(
        entry?.value ?? 0,
        entry?.compare_value,
        item.delta_good ?? 'up',
    );
}

const t = themeTokens(useRuntimeTheme());

// Semantic tint for the card icon, from the metric's goodness direction:
// a "higher is better" KPI reads positive (emerald), a "lower is better" one
// (delays, errors) reads as a watch-metric (amber). Neutral KPIs stay muted.
function iconTint(item: MetricItem): string {
    if (item.delta_good === 'up') {
        return 'text-emerald-500';
    }
    if (item.delta_good === 'down') {
        return 'text-amber-500';
    }
    return t.textSubtle;
}

const gridClass = computed(() => {
    const cols = props.block.columns ?? 3;
    return (
        {
            1: 'grid-cols-1',
            2: 'grid-cols-1 sm:grid-cols-2',
            3: 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3',
            4: 'grid-cols-2 lg:grid-cols-4',
            5: 'grid-cols-2 lg:grid-cols-5',
            6: 'grid-cols-2 md:grid-cols-3 lg:grid-cols-6',
        }[cols] ?? 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3'
    );
});

function format(item: MetricItem, raw: number | undefined): string {
    const v = raw ?? 0;
    if (item.format === 'currency') {
        const obj = props.objects.find((o) => o.id === item.query.object_id);
        const field = obj?.fields.find((f) => f.id === item.field_id);
        const code = field?.currency_code ?? props.defaultCurrency ?? 'MXN';
        return new Intl.NumberFormat(props.locale, {
            style: 'currency',
            currency: code,
        }).format(v);
    }
    if (item.format === 'percentage') {
        return new Intl.NumberFormat(props.locale, {
            style: 'percent',
            maximumFractionDigits: 1,
        }).format(v);
    }
    return new Intl.NumberFormat(props.locale).format(v);
}
</script>

<template>
    <div :class="['grid gap-3', gridClass]">
        <div
            v-for="item in block.items"
            :key="item.id"
            :class="['rounded-sp-sm border p-5', t.surface]"
        >
            <div class="flex items-start justify-between gap-2">
                <p
                    :class="[
                        'text-[11px] tracking-wider uppercase',
                        t.textSubtle,
                    ]"
                >
                    {{ item.label }}
                </p>
                <RuntimeIcon
                    v-if="item.icon"
                    :name="item.icon"
                    :size="16"
                    :class="iconTint(item)"
                />
            </div>
            <div class="mt-2 flex items-end justify-between gap-3">
                <p :class="['text-2xl font-bold tracking-tight', t.statTint]">
                    {{ format(item, data?.items?.[item.id]?.value) }}
                </p>
                <MiniSparkline
                    v-if="item.spark"
                    :rows="data?.items?.[item.id]?.spark_rows"
                    :object="sparkObjectFor(item)"
                    :x-field-id="item.spark.x_field_id"
                    :y-field-id="item.spark.y_field_id"
                    :aggregation="item.spark.aggregation"
                    :color="item.spark.color"
                />
            </div>
            <div
                v-if="trendFor(item) || item.compare_label"
                class="mt-1 flex items-center justify-between gap-2"
            >
                <p
                    v-if="trendFor(item)"
                    class="inline-flex items-center gap-1 text-xs font-semibold"
                    :class="
                        trendFor(item)!.dir === 'flat'
                            ? t.textSubtle
                            : trendFor(item)!.good
                              ? 'text-emerald-500'
                              : 'text-red-500'
                    "
                >
                    <span v-if="trendFor(item)!.dir === 'up'">▲</span
                    ><span v-else-if="trendFor(item)!.dir === 'down'">▼</span
                    ><span v-else>→</span>
                    {{ trendFor(item)!.label }}
                </p>
                <span v-else />
                <span
                    v-if="item.compare_label"
                    :class="['text-[11px]', t.textMuted]"
                >
                    {{ item.compare_label }}
                </span>
            </div>
        </div>
    </div>
</template>
