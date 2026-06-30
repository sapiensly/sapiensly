<script setup lang="ts">
import { computed } from 'vue';
import RuntimeIcon from '../RuntimeIcon.vue';
import type { ObjectDef } from '../types/manifest';
import { computeTrend } from './trend';

type Variant =
    | 'insight'
    | 'recommendation'
    | 'conclusion'
    | 'positive'
    | 'warning'
    | 'risk';

interface InsightCompute {
    query: { object_id: string };
    aggregation: string;
    field_id?: string;
    format?: 'number' | 'currency' | 'percentage' | 'duration';
    compare?: unknown;
    delta_good?: 'up' | 'down';
}

interface InsightBlock {
    id: string;
    type: 'insight';
    variant?: Variant;
    title: string;
    body?: string;
    icon?: string;
    metric?: string;
    compute?: InsightCompute;
}

interface InsightData {
    value?: number;
    compare_value?: number | null;
}

defineOptions({ inheritAttrs: false });

const props = defineProps<{
    block: InsightBlock;
    data?: InsightData;
    objects?: ObjectDef[];
    locale?: string;
    defaultCurrency?: string;
}>();

const VARIANTS: Record<
    Variant,
    { color: string; icon: string; label: string }
> = {
    insight: { color: '#3B82F6', icon: '💡', label: 'Insight' },
    recommendation: { color: '#8B5CF6', icon: '🎯', label: 'Recomendación' },
    conclusion: { color: '#0EA5E9', icon: '📌', label: 'Conclusión' },
    positive: { color: '#10B981', icon: '✅', label: 'Positivo' },
    warning: { color: '#F59E0B', icon: '⚠️', label: 'Atención' },
    risk: { color: '#EF4444', icon: '🚩', label: 'Riesgo' },
};

const v = computed(() => VARIANTS[props.block.variant ?? 'insight']);

// A computed figure (block.compute) overrides any static `metric`.
const hasComputed = computed(
    () => !!props.block.compute && typeof props.data?.value === 'number',
);

const computedMetric = computed(() => {
    if (!hasComputed.value) return null;
    const c = props.block.compute!;
    const value = props.data!.value as number;
    if (c.format === 'currency') {
        const obj = props.objects?.find((o) => o.id === c.query.object_id);
        const field = obj?.fields.find((f) => f.id === c.field_id);
        const code = field?.currency_code ?? props.defaultCurrency ?? 'MXN';
        return new Intl.NumberFormat(props.locale, {
            style: 'currency',
            currency: code,
        }).format(value);
    }
    if (c.format === 'percentage') {
        return new Intl.NumberFormat(props.locale, {
            style: 'percent',
            maximumFractionDigits: 1,
        }).format(value);
    }
    return new Intl.NumberFormat(props.locale).format(value);
});

const trend = computed(() =>
    hasComputed.value
        ? computeTrend(
              props.data!.value as number,
              props.data?.compare_value,
              props.block.compute?.delta_good ?? 'up',
          )
        : null,
);

// The big figure on the right: computed when present, else the static metric.
const displayMetric = computed(
    () => computedMetric.value ?? props.block.metric,
);
</script>

<template>
    <div
        class="flex gap-4 rounded-xl border border-l-4 p-5"
        :style="{
            borderColor: 'color-mix(in srgb, currentColor 12%, transparent)',
            borderLeftColor: v.color,
            backgroundColor: `color-mix(in srgb, ${v.color} 7%, transparent)`,
        }"
    >
        <div><RuntimeIcon :name="block.icon || v.icon" :size="24" /></div>
        <div class="min-w-0 flex-1">
            <div
                class="text-[11px] font-semibold tracking-wider uppercase"
                :style="{ color: v.color }"
            >
                {{ v.label }}
            </div>
            <h3 class="mt-0.5 text-base leading-snug font-semibold">
                {{ block.title }}
            </h3>
            <p
                v-if="block.body"
                class="mt-1.5 text-sm leading-relaxed"
                :style="{ opacity: 0.8 }"
            >
                {{ block.body }}
            </p>
        </div>
        <div
            v-if="displayMetric"
            class="flex shrink-0 flex-col items-end self-center"
        >
            <span
                class="text-2xl font-bold tracking-tight"
                :style="{ color: v.color }"
            >
                {{ displayMetric }}
            </span>
            <span
                v-if="trend"
                class="mt-0.5 inline-flex items-center gap-1 text-xs font-semibold"
                :class="
                    trend.dir === 'flat'
                        ? 'opacity-60'
                        : trend.good
                          ? 'text-emerald-500'
                          : 'text-red-500'
                "
            >
                <span v-if="trend.dir === 'up'">▲</span>
                <span v-else-if="trend.dir === 'down'">▼</span>
                <span v-else>→</span>
                {{ trend.label }}
            </span>
        </div>
    </div>
</template>
