<script setup lang="ts">
import { computed } from 'vue';
import type { BlockStat, ObjectDef, StatBlockData } from '../types/manifest';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';

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

const formatted = computed(() => {
    const v = value.value;
    if (props.block.format === 'currency') {
        const obj = props.objects.find((o) => o.id === props.block.query.object_id);
        const field = obj?.fields.find((f) => f.id === props.block.field_id);
        const code = field?.currency_code ?? props.defaultCurrency ?? 'MXN';
        return new Intl.NumberFormat(props.locale, { style: 'currency', currency: code }).format(v);
    }
    if (props.block.format === 'percentage') {
        return new Intl.NumberFormat(props.locale, { style: 'percent', maximumFractionDigits: 1 }).format(v);
    }
    return new Intl.NumberFormat(props.locale).format(v);
});
</script>

<template>
    <div :class="['rounded-sp-sm border p-5', t.surface]">
        <p :class="['text-[11px] uppercase tracking-wider', t.textSubtle]">
            {{ block.label }}
        </p>
        <p :class="['mt-2 text-2xl font-semibold', t.statTint]">{{ formatted }}</p>
    </div>
</template>
