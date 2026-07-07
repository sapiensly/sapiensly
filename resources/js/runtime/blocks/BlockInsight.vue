<script setup lang="ts">
import { computed } from 'vue';
import RuntimeIcon from '../RuntimeIcon.vue';
import type { ObjectDef } from '../types/manifest';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';
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
    metric_label?: string;
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

// Lucide names (not emojis) for a clean executive eyebrow that inherits the
// variant colour; a block.icon override still wins (and may be an emoji).
const VARIANTS: Record<
    Variant,
    { color: string; icon: string; label: string }
> = {
    insight: { color: '#3B82F6', icon: 'lightbulb', label: 'Insight' },
    recommendation: { color: '#6366F1', icon: 'target', label: 'Recomendación' },
    conclusion: { color: '#3B82F6', icon: 'bar-chart', label: 'Conclusión' },
    positive: { color: '#10B981', icon: 'check-circle', label: 'Positivo' },
    warning: { color: '#F59E0B', icon: 'alert-triangle', label: 'Atención' },
    risk: { color: '#EF4444', icon: 'flag', label: 'Riesgo' },
};

const v = computed(() => VARIANTS[props.block.variant ?? 'insight']);
const t = themeTokens(useRuntimeTheme());

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
        class="flex items-start gap-5 rounded-2xl border p-6"
        :style="{
            borderColor: `color-mix(in srgb, ${v.color} 16%, transparent)`,
            backgroundColor: `color-mix(in srgb, ${v.color} 6%, transparent)`,
        }"
    >
        <div class="min-w-0 flex-1">
            <div
                class="flex items-center gap-1.5 text-[11px] font-bold tracking-[0.12em] uppercase"
                :style="{ color: v.color }"
            >
                <RuntimeIcon :name="block.icon || v.icon" :size="15" />
                <span>{{ v.label }}</span>
            </div>
            <h3
                class="mt-2.5 text-lg leading-snug font-bold"
                :class="t.text"
            >
                {{ block.title }}
            </h3>
            <p
                v-if="block.body"
                class="mt-2 text-sm leading-relaxed"
                :class="t.textMuted"
            >
                {{ block.body }}
            </p>
        </div>
        <div
            v-if="displayMetric"
            class="flex shrink-0 flex-col items-end pt-6 text-right"
        >
            <span
                class="text-5xl leading-none font-extrabold tracking-tight tabular-nums"
                :style="{ color: v.color }"
            >
                {{ displayMetric }}
            </span>
            <span
                v-if="block.metric_label"
                class="mt-2 text-xs font-medium"
                :class="t.textMuted"
            >
                {{ block.metric_label }}
            </span>
            <span
                v-if="trend"
                class="mt-2 inline-flex items-center gap-1 text-xs font-semibold"
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
