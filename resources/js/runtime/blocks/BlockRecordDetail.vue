<script setup lang="ts">
import { computed } from 'vue';
import type { FieldDef, ObjectDef } from '../types/manifest';
import { resolveField } from '../types/manifest';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';

interface DetailField {
    field_id: string;
    label_override?: string;
}

interface RecordDetailBlock {
    id: string;
    type: 'record_detail';
    label?: string;
    object_id: string;
    record_id_expression: string;
    fields: DetailField[];
}

const props = defineProps<{
    block: RecordDetailBlock;
    data: { record: { id: string; data: Record<string, unknown> } | null } | undefined;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

const t = themeTokens(useRuntimeTheme());

const object = computed<ObjectDef | undefined>(() =>
    props.objects.find((o) => o.id === props.block.object_id),
);
const record = computed(() => props.data?.record ?? null);

const rows = computed(() =>
    props.block.fields.map((f) => {
        const field = resolveField(object.value, f.field_id);
        return {
            key: f.field_id,
            label: f.label_override ?? field?.name ?? f.field_id,
            value: field ? format(field, record.value?.data?.[field.slug]) : '—',
        };
    }),
);

function format(field: FieldDef, value: unknown): string {
    if (value === null || value === undefined || value === '') return '—';
    if (field.type === 'currency' && typeof value === 'number') {
        return new Intl.NumberFormat(props.locale, {
            style: 'currency',
            currency: field.currency_code ?? props.defaultCurrency ?? 'MXN',
        }).format(value);
    }
    if (field.type === 'number' && typeof value === 'number') {
        return new Intl.NumberFormat(props.locale).format(value);
    }
    if (field.type === 'boolean') return value ? '✓' : '—';
    if (field.type === 'single_select') {
        return field.options?.find((o) => o.value === value)?.label ?? String(value);
    }
    if (field.type === 'multi_select' && Array.isArray(value)) {
        return value
            .map((v) => field.options?.find((o) => o.value === v)?.label ?? String(v))
            .join(', ');
    }
    if (field.type === 'date' || field.type === 'datetime') {
        try {
            const d = new Date(String(value));
            return field.type === 'date' ? d.toLocaleDateString(props.locale) : d.toLocaleString(props.locale);
        } catch {
            return String(value);
        }
    }
    if (field.type === 'rating') {
        const n = Number(value);
        const max = (field as unknown as { max?: number }).max ?? 5;
        return '★'.repeat(Math.max(0, Math.min(max, Math.round(n)))) + ` ${n}/${max}`;
    }
    if (field.type === 'date_range' && value && typeof value === 'object') {
        const r = value as { from?: string; to?: string };
        const fmt = (s?: string) => (s ? new Date(s).toLocaleDateString(props.locale) : '—');
        return `${fmt(r.from)} → ${fmt(r.to)}`;
    }
    if (field.type === 'file' && value && typeof value === 'object') {
        return (value as { original_name?: string }).original_name ?? 'file';
    }
    return String(value);
}
</script>

<template>
    <div :class="['rounded-sp-sm border p-5', t.surface]">
        <p v-if="block.label" :class="['mb-3 text-[11px] uppercase tracking-wider', t.textSubtle]">{{ block.label }}</p>

        <p v-if="!record" :class="['py-6 text-center text-xs', t.textMuted]">No record selected.</p>

        <dl v-else class="divide-y divide-soft">
            <div v-for="row in rows" :key="row.key" class="flex justify-between gap-4 py-2">
                <dt :class="['text-xs', t.textMuted]">{{ row.label }}</dt>
                <dd :class="['text-right text-sm font-medium', t.text]">{{ row.value }}</dd>
            </div>
        </dl>
    </div>
</template>
