<script setup lang="ts">
import { computed } from 'vue';
import type { FieldDef, ObjectDef } from '../types/manifest';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';

interface KanbanBlock {
    id: string;
    type: 'kanban';
    data_source: { object_id: string };
    group_by_field_id: string;
    card_title_field_id: string;
    card_meta_fields?: Array<{ field_id: string }>;
}

interface RowData {
    id: string;
    data: Record<string, unknown>;
}

const props = defineProps<{
    block: KanbanBlock;
    data: { rows: RowData[] } | undefined;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

const t = themeTokens(useRuntimeTheme());

const object = computed<ObjectDef | undefined>(() =>
    props.objects.find((o) => o.id === props.block.data_source.object_id),
);

function fieldOf(id: string | undefined): FieldDef | undefined {
    if (!id) return undefined;
    return object.value?.fields.find((f) => f.id === id);
}

const groupField = computed(() => fieldOf(props.block.group_by_field_id));
const titleField = computed(() => fieldOf(props.block.card_title_field_id));
const metaFields = computed<FieldDef[]>(() =>
    (props.block.card_meta_fields ?? [])
        .map((m) => fieldOf(m.field_id))
        .filter((f): f is FieldDef => f !== undefined),
);

interface Column {
    value: string;
    label: string;
    color: string | null;
    rows: RowData[];
}

const columns = computed<Column[]>(() => {
    const rows = props.data?.rows ?? [];
    const gf = groupField.value;

    // Seed columns from options if group_by is single_select — empty columns
    // are still useful in kanban (a status with zero items).
    const cols = new Map<string, Column>();
    if (gf?.type === 'single_select' && gf.options) {
        for (const opt of gf.options) {
            cols.set(opt.value, {
                value: opt.value,
                label: opt.label,
                color: opt.color ?? null,
                rows: [],
            });
        }
    }

    for (const r of rows) {
        const raw = gf ? r.data[gf.slug] : null;
        const key = raw === null || raw === undefined || raw === '' ? '__none__' : String(raw);
        if (!cols.has(key)) {
            const opt = gf?.options?.find((o) => o.value === key);
            cols.set(key, {
                value: key,
                label: opt?.label ?? (key === '__none__' ? '—' : key),
                color: opt?.color ?? null,
                rows: [],
            });
        }
        cols.get(key)!.rows.push(r);
    }

    return Array.from(cols.values());
});

function formatMeta(field: FieldDef, value: unknown): string {
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
        const opt = field.options?.find((o) => o.value === value);
        return opt?.label ?? String(value);
    }
    if (field.type === 'boolean') return value ? '✓' : '—';
    if (field.type === 'date' || field.type === 'datetime') {
        try {
            const d = new Date(String(value));
            return field.type === 'date'
                ? d.toLocaleDateString(props.locale)
                : d.toLocaleString(props.locale);
        } catch {
            return String(value);
        }
    }
    return String(value);
}

function titleFor(row: RowData): string {
    if (!titleField.value) return row.id;
    const v = row.data[titleField.value.slug];
    return v === null || v === undefined || v === '' ? row.id : String(v);
}
</script>

<template>
    <div class="overflow-x-auto">
        <div class="flex gap-3 pb-2">
            <div
                v-for="col in columns"
                :key="col.value"
                :class="['flex w-72 shrink-0 flex-col rounded-sp-sm border', t.surface]"
            >
                <header class="flex items-center justify-between gap-2 border-b border-soft px-3 py-2">
                    <div class="flex items-center gap-2 min-w-0">
                        <span
                            v-if="col.color"
                            class="size-2.5 shrink-0 rounded-pill"
                            :style="{ background: col.color }"
                        />
                        <span :class="['truncate text-xs font-medium', t.text]">{{ col.label }}</span>
                    </div>
                    <span :class="['rounded-pill border border-soft px-1.5 text-[10px]', t.textMuted]">
                        {{ col.rows.length }}
                    </span>
                </header>
                <ul class="flex-1 space-y-2 p-2">
                    <li v-if="col.rows.length === 0" :class="['py-4 text-center text-[11px]', t.textSubtle]">
                        Empty
                    </li>
                    <li
                        v-for="row in col.rows"
                        :key="row.id"
                        :class="['rounded-xs border p-2', t.surfaceMuted]"
                    >
                        <p :class="['text-xs font-medium', t.text]">
                            {{ titleFor(row) }}
                        </p>
                        <dl v-if="metaFields.length" :class="['mt-1 space-y-0.5 text-[11px]', t.textMuted]">
                            <div v-for="f in metaFields" :key="f.id" class="flex items-center justify-between gap-2">
                                <dt class="truncate">{{ f.name }}</dt>
                                <dd :class="['truncate', t.text]">{{ formatMeta(f, row.data[f.slug]) }}</dd>
                            </div>
                        </dl>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
