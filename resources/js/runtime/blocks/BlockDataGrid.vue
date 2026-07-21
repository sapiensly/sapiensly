<script setup lang="ts">
import { computed, inject, ref, watch } from 'vue';
import type { FieldDef, ObjectDef } from '../types/manifest';
import { resolveField } from '../types/manifest';
import { useActionExecutor } from '../useActionExecutor';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';

interface Column {
    field_id: string;
    label_override?: string;
    editable?: boolean;
}

interface DataGridBlock {
    id: string;
    type: 'data_grid';
    label?: string;
    data_source: { object_id: string };
    columns: Column[];
}

interface RowData {
    id: string;
    data: Record<string, unknown>;
}

const props = defineProps<{
    block: DataGridBlock;
    data: { rows: RowData[] } | undefined;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

const t = themeTokens(useRuntimeTheme());
const { execute } = useActionExecutor();
const appSlug = inject<string>('appSlug', '');

const object = computed<ObjectDef | undefined>(() =>
    props.objects.find((o) => o.id === props.block.data_source.object_id),
);

// Field types whose value can be edited with a simple inline input. Computed
// fields (formula/lookup/rollup), relations and files stay read-only.
const EDITABLE_TYPES = ['string', 'email', 'url', 'phone', 'long_text', 'number', 'currency', 'slider', 'single_select', 'boolean', 'date', 'datetime'];

const columns = computed(() =>
    props.block.columns.map((c) => {
        const field = resolveField(object.value, c.field_id);
        return {
            key: c.field_id,
            field,
            label: c.label_override ?? field?.name ?? c.field_id,
            editable: c.editable !== false && !!field && EDITABLE_TYPES.includes(field.type),
        };
    }),
);

// Local, mutable copy so an edited cell updates instantly (optimistic) without a
// full page reload; re-synced whenever the server sends fresh rows.
const rows = ref<RowData[]>([]);
watch(
    () => props.data?.rows,
    (next) => {
        rows.value = (next ?? []).map((r) => ({ id: r.id, data: { ...r.data } }));
    },
    { immediate: true },
);

function coerce(field: FieldDef, raw: unknown): unknown {
    if (field.type === 'boolean') return !!raw;
    if (['number', 'currency', 'slider'].includes(field.type)) {
        if (raw === '' || raw === null || raw === undefined) return null;
        const n = Number(raw);
        return Number.isFinite(n) ? n : null;
    }
    return raw === '' ? null : raw;
}

async function saveCell(rowIndex: number, field: FieldDef, raw: unknown) {
    const row = rows.value[rowIndex];
    if (!row) return;
    const value = coerce(field, raw);
    const previous = row.data[field.slug];
    if (value === previous) return;

    row.data[field.slug] = value; // optimistic

    const res = await execute(
        [
            {
                type: 'update_record',
                object_id: props.block.data_source.object_id,
                record_id_expression: '{{row.id}}',
                values: { [field.slug]: value },
            },
        ],
        { appSlug, row: { id: row.id, data: {} } },
    );

    if (!res.ok) {
        row.data[field.slug] = previous; // revert
    }
}

function onInputChange(rowIndex: number, field: FieldDef, event: Event) {
    const el = event.target as HTMLInputElement;
    saveCell(rowIndex, field, field.type === 'boolean' ? el.checked : el.value);
}

function onSelectChange(rowIndex: number, field: FieldDef, event: Event) {
    saveCell(rowIndex, field, (event.target as HTMLSelectElement).value);
}

// Read-only display (computed/relation/etc.) — formatted by type.
function display(field: FieldDef | undefined, value: unknown): string {
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
    if (field.type === 'single_select') {
        return field.options?.find((o) => o.value === value)?.label ?? String(value);
    }
    if (field.type === 'boolean') return value ? '✓' : '—';
    if (field.type === 'date' || field.type === 'datetime') {
        try {
            const d = new Date(String(value));
            return field.type === 'date' ? d.toLocaleDateString(props.locale) : d.toLocaleString(props.locale);
        } catch {
            return String(value);
        }
    }
    return String(value);
}

// Date inputs want YYYY-MM-DD; trim an ISO datetime to its date part.
function dateValue(value: unknown): string {
    if (typeof value !== 'string' || value === '') return '';
    return value.slice(0, 10);
}

const inputClass = 'w-full rounded-xs border border-medium bg-surface px-2 py-1 text-sm focus:border-accent-blue focus:outline-none';
</script>

<template>
    <div :class="['overflow-x-auto rounded-sp-sm border', t.surface]">
        <p v-if="block.label" :class="['border-b border-soft px-3 py-2 text-[11px] uppercase tracking-wider', t.textSubtle]">
            {{ block.label }}
        </p>

        <p v-if="rows.length === 0" :class="['px-3 py-6 text-center text-xs', t.textMuted]">No records.</p>

        <table v-else class="w-full text-left text-sm">
            <thead>
                <tr :class="['border-b border-soft', t.textMuted]">
                    <th v-for="col in columns" :key="col.key" class="px-3 py-2 text-[11px] font-medium uppercase tracking-wider">
                        {{ col.label }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(row, i) in rows" :key="row.id" class="border-b border-soft/60 last:border-0">
                    <td v-for="col in columns" :key="col.key" :class="['px-2 py-1', t.text]">
                        <template v-if="col.editable && col.field">
                            <input
                                v-if="col.field.type === 'boolean'"
                                type="checkbox"
                                class="size-4 accent-accent-blue"
                                :checked="!!row.data[col.field.slug]"
                                @change="onInputChange(i, col.field, $event)"
                            />
                            <select
                                v-else-if="col.field.type === 'single_select'"
                                :class="inputClass"
                                :value="(row.data[col.field.slug] as string) ?? ''"
                                @change="onSelectChange(i, col.field, $event)"
                            >
                                <option value="">—</option>
                                <option v-for="o in col.field.options ?? []" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </select>
                            <input
                                v-else-if="col.field.type === 'number' || col.field.type === 'currency' || col.field.type === 'slider'"
                                type="number"
                                :class="inputClass"
                                :value="row.data[col.field.slug] as number"
                                @change="onInputChange(i, col.field, $event)"
                            />
                            <input
                                v-else-if="col.field.type === 'date' || col.field.type === 'datetime'"
                                type="date"
                                :class="inputClass"
                                :value="dateValue(row.data[col.field.slug])"
                                @change="onInputChange(i, col.field, $event)"
                            />
                            <input
                                v-else
                                type="text"
                                :class="inputClass"
                                :value="(row.data[col.field.slug] as string) ?? ''"
                                @change="onInputChange(i, col.field, $event)"
                            />
                        </template>
                        <span v-else class="px-1">{{ display(col.field, row.data[col.field?.slug ?? '']) }}</span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
