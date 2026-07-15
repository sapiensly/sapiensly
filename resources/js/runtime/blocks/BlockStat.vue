<script setup lang="ts">
import { computed } from 'vue';
import RuntimeIcon from '../RuntimeIcon.vue';
import type { BlockStat, ObjectDef, StatBlockData } from '../types/manifest';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';
import { formatPercent } from './formatPercent';
import MiniSparkline from './MiniSparkline.vue';
import { computeTrend } from './trend';

const props = defineProps<{
    block: BlockStat;
    data: StatBlockData | undefined;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

const theme = useRuntimeTheme();
const t = themeTokens(theme);

// Semantic icon tint: positive metric → emerald, watch-metric → amber, else muted.
const iconTint = computed(() => {
    if (props.block.delta_good === 'up') {
        return 'text-emerald-500';
    }
    if (props.block.delta_good === 'down') {
        return 'text-amber-500';
    }
    return t.textSubtle;
});

const value = computed(() => props.data?.value ?? 0);

const sparkObject = computed<ObjectDef | undefined>(() =>
    props.objects.find(
        (o) => o.id === props.block.spark?.data_source.object_id,
    ),
);

const trend = computed(() =>
    computeTrend(
        value.value,
        props.data?.compare_value,
        props.block.delta_good ?? 'up',
    ),
);

const formatted = computed(() => {
    const v = value.value;
    if (props.block.format === 'currency') {
        const obj = props.objects.find(
            (o) => o.id === props.block.query.object_id,
        );
        const field = obj?.fields.find((f) => f.id === props.block.field_id);
        const code = field?.currency_code ?? props.defaultCurrency ?? 'MXN';
        return new Intl.NumberFormat(props.locale, {
            style: 'currency',
            currency: code,
        }).format(v);
    }
    if (props.block.format === 'percentage') {
        return formatPercent(v, props.data?.value_scale, props.locale);
    }
    return new Intl.NumberFormat(props.locale).format(v);
});
</script>

<template>
    <div :class="['rounded-sp-sm border p-5', t.surface]">
        <div class="flex items-start justify-between gap-2">
            <p :class="['text-[11px] tracking-wider uppercase', t.textSubtle]">
                {{ block.label }}
            </p>
            <RuntimeIcon
                v-if="block.icon"
                :name="block.icon"
                :size="16"
                :class="iconTint"
            />
        </div>
        <div class="mt-2 flex items-end justify-between gap-3">
            <p :class="['text-3xl font-bold tracking-tight', t.statTint]">
                {{ formatted }}
            </p>
            <MiniSparkline
                v-if="block.spark"
                :rows="data?.spark_rows"
                :object="sparkObject"
                :x-field-id="block.spark.x_field_id"
                :y-field-id="block.spark.y_field_id"
                :aggregation="block.spark.aggregation"
                :color="block.spark.color"
            />
        </div>
        <p v-if="block.subtitle" :class="['mt-1 text-[11px]', t.textMuted]">
            {{ block.subtitle }}
        </p>
        <div
            v-if="trend || block.compare_label"
            class="mt-1.5 flex items-center justify-between gap-2"
        >
            <p
                v-if="trend"
                class="inline-flex items-center gap-1 text-xs font-semibold"
                :class="
                    trend.dir === 'flat'
                        ? t.textSubtle
                        : trend.good
                          ? 'text-emerald-500'
                          : 'text-red-500'
                "
            >
                <span v-if="trend.dir === 'up'">▲</span
                ><span v-else-if="trend.dir === 'down'">▼</span
                ><span v-else>→</span>
                {{ trend.label }}
            </p>
            <span v-else />
            <span
                v-if="block.compare_label"
                :class="['text-[11px]', t.textMuted]"
            >
                {{ block.compare_label }}
            </span>
        </div>
    </div>
</template>
