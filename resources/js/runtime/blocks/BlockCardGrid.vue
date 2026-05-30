<script setup lang="ts">
import { computed } from 'vue';
import type { FieldDef, ObjectDef } from '../types/manifest';
import { resolveField } from '../types/manifest';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';

interface CardGridBlock {
    id: string;
    type: 'card_grid';
    data_source: { object_id: string };
    columns?: number;
    title_field_id: string;
    subtitle_field_id?: string;
    image_field_id?: string;
    meta_fields?: Array<{ field_id: string }>;
}

interface RowData {
    id: string;
    data: Record<string, unknown>;
}

const props = defineProps<{
    block: CardGridBlock;
    data: { rows: RowData[] } | undefined;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

const t = themeTokens(useRuntimeTheme());

const object = computed<ObjectDef | undefined>(() =>
    props.objects.find((o) => o.id === props.block.data_source.object_id),
);

function fieldOf(id?: string): FieldDef | undefined {
    return resolveField(object.value, id);
}

const titleField = computed(() => fieldOf(props.block.title_field_id));
const subtitleField = computed(() => fieldOf(props.block.subtitle_field_id));
const imageField = computed(() => fieldOf(props.block.image_field_id));
const metaFields = computed<FieldDef[]>(() =>
    (props.block.meta_fields ?? [])
        .map((m) => fieldOf(m.field_id))
        .filter((f): f is FieldDef => f !== undefined),
);

const gridClass = computed(() => {
    const cols = props.block.columns ?? 3;
    return {
        1: 'grid-cols-1',
        2: 'grid-cols-1 sm:grid-cols-2',
        3: 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3',
        4: 'grid-cols-2 lg:grid-cols-4',
        5: 'grid-cols-2 lg:grid-cols-5',
        6: 'grid-cols-2 md:grid-cols-3 lg:grid-cols-6',
    }[cols] ?? 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3';
});

const rows = computed(() => props.data?.rows ?? []);

function formatValue(field: FieldDef, value: unknown): string {
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

function titleOf(row: RowData): string {
    if (!titleField.value) return row.id;
    const v = row.data[titleField.value.slug];
    return v === null || v === undefined || v === '' ? row.id : String(v);
}

function imageSrc(row: RowData): string | null {
    if (!imageField.value) return null;
    const v = row.data[imageField.value.slug];
    return typeof v === 'string' && v !== '' ? v : null;
}
</script>

<template>
    <div :class="['grid gap-3', gridClass]">
        <p v-if="rows.length === 0" :class="['col-span-full py-8 text-center text-xs', t.textMuted]">
            No records.
        </p>
        <article
            v-for="row in rows"
            :key="row.id"
            :class="['overflow-hidden rounded-sp-sm border', t.surface]"
        >
            <img
                v-if="imageSrc(row)"
                :src="imageSrc(row)!"
                :alt="titleOf(row)"
                class="h-32 w-full object-cover"
                loading="lazy"
            />
            <div class="space-y-1.5 p-4">
                <p :class="['text-sm font-semibold', t.text]">{{ titleOf(row) }}</p>
                <p
                    v-if="subtitleField && row.data[subtitleField.slug]"
                    :class="['truncate text-xs', t.textMuted]"
                >
                    {{ formatValue(subtitleField, row.data[subtitleField.slug]) }}
                </p>
                <dl
                    v-if="metaFields.length"
                    :class="['mt-2 space-y-0.5 text-[11px]', t.textMuted]"
                >
                    <div v-for="f in metaFields" :key="f.id" class="flex items-center justify-between gap-2">
                        <dt class="truncate">{{ f.name }}</dt>
                        <dd :class="['truncate', t.text]">{{ formatValue(f, row.data[f.slug]) }}</dd>
                    </div>
                </dl>
            </div>
        </article>
    </div>
</template>
