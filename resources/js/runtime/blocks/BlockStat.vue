<script setup lang="ts">
import { computed } from 'vue';
import RuntimeIcon from '../RuntimeIcon.vue';
import type { BlockStat, ObjectDef, StatBlockData } from '../types/manifest';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';
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

const value = computed(() => props.data?.value ?? 0);

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
        return new Intl.NumberFormat(props.locale, {
            style: 'percent',
            maximumFractionDigits: 1,
        }).format(v);
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
                :class="t.textSubtle"
            />
        </div>
        <p :class="['mt-2 text-3xl font-bold tracking-tight', t.statTint]">
            {{ formatted }}
        </p>
        <p
            v-if="trend"
            class="mt-1.5 inline-flex items-center gap-1 text-xs font-semibold"
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
    </div>
</template>
