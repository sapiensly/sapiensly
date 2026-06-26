<script setup lang="ts">
import { computed } from 'vue';
import type { FieldDef, ObjectDef } from '../types/manifest';
import { resolveField } from '../types/manifest';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';

interface Column {
    field_id: string;
    label_override?: string;
}

interface RelatedListBlock {
    id: string;
    type: 'related_list';
    label?: string;
    object_id: string;
    via_relation_field_id: string;
    parent_id_expression: string;
    columns: Column[];
}

interface RowData {
    id: string;
    data: Record<string, unknown>;
}

const props = defineProps<{
    block: RelatedListBlock;
    data: { rows: RowData[] } | undefined;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

const t = themeTokens(useRuntimeTheme());

const object = computed<ObjectDef | undefined>(() =>
    props.objects.find((o) => o.id === props.block.object_id),
);

const columns = computed(() =>
    props.block.columns.map((c) => {
        const field = resolveField(object.value, c.field_id);
        return { key: c.field_id, field, label: c.label_override ?? field?.name ?? c.field_id };
    }),
);

const rows = computed<RowData[]>(() => props.data?.rows ?? []);

function format(field: FieldDef | undefined, value: unknown): string {
    if (!field) return '—';
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
    return String(value);
}
</script>

<template>
    <div :class="['overflow-hidden rounded-sp-sm border', t.surface]">
        <p v-if="block.label" :class="['border-b border-soft px-3 py-2 text-[11px] uppercase tracking-wider', t.textSubtle]">
            {{ block.label }}
        </p>

        <p v-if="rows.length === 0" :class="['px-3 py-6 text-center text-xs', t.textMuted]">No related records.</p>

        <table v-else class="w-full text-left text-sm">
            <thead>
                <tr :class="['border-b border-soft', t.textMuted]">
                    <th v-for="col in columns" :key="col.key" class="px-3 py-2 text-[11px] font-medium uppercase tracking-wider">
                        {{ col.label }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in rows" :key="row.id" class="border-b border-soft/60 last:border-0">
                    <td v-for="col in columns" :key="col.key" :class="['px-3 py-2', t.text]">
                        {{ format(col.field, row.data[col.field?.slug ?? '']) }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
