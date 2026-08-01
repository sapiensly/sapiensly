<script setup lang="ts">
import { computed } from 'vue';
import type { FieldDef, ObjectDef } from '../types/manifest';
import { resolveField } from '../types/manifest';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';
import FieldValue from './FieldValue.vue';
import { type DisplayContext } from './fieldDisplay';

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
    data:
        | {
              record: {
                  id: string;
                  data: Record<string, unknown>;
                  labels?: Record<string, unknown>;
              } | null;
          }
        | undefined;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

const t = themeTokens(useRuntimeTheme());

const object = computed<ObjectDef | undefined>(() =>
    props.objects.find((o) => o.id === props.block.object_id),
);
const record = computed(() => props.data?.record ?? null);

const context = computed<DisplayContext>(() => ({
    locale: props.locale,
    defaultCurrency: props.defaultCurrency,
    labels: record.value?.labels,
    objects: props.objects,
}));

/**
 * Prose spans the full row; everything else pairs up across the grid.
 *
 * A description sharing a 3-up row with a status wraps to five cramped lines
 * beside two words, so the long ones get the whole width and the short ones
 * stay side by side.
 */
const WIDE_TYPES = new Set(['long_text', 'rich_text', 'file', 'date_range']);

interface DetailRow {
    key: string;
    label: string;
    field?: FieldDef;
    value: unknown;
    wide: boolean;
}

const rows = computed<DetailRow[]>(() =>
    props.block.fields.map((f) => {
        const field = resolveField(object.value, f.field_id);
        return {
            key: f.field_id,
            label: f.label_override ?? field?.name ?? f.field_id,
            field,
            value: field ? record.value?.data?.[field.slug] : undefined,
            wide: WIDE_TYPES.has(field?.type ?? ''),
        };
    }),
);
</script>

<template>
    <!--
        Stacked pairs on a responsive grid, not a label/value row split across
        the card. The old layout pushed every value hard right, so on a wide
        screen a label sat a thousand pixels from the thing it named and reading
        one record meant sweeping left to right per line. Label above value
        keeps the two together at any width, and the grid uses the space the
        card actually has instead of padding it with air.
    -->
    <div :class="['rounded-sp-sm border p-5', t.surface]">
        <p
            v-if="block.label"
            :class="['mb-4 text-[11px] tracking-wider uppercase', t.textSubtle]"
        >
            {{ block.label }}
        </p>

        <p v-if="!record" :class="['py-6 text-center text-xs', t.textMuted]">
            No record selected.
        </p>

        <dl
            v-else
            class="grid grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-2 xl:grid-cols-3"
        >
            <div
                v-for="row in rows"
                :key="row.key"
                :class="row.wide ? 'sm:col-span-2 xl:col-span-3' : ''"
            >
                <dt
                    :class="[
                        'mb-1 text-[11px] tracking-wide uppercase',
                        t.textSubtle,
                    ]"
                >
                    {{ row.label }}
                </dt>
                <dd :class="['text-sm', t.text]">
                    <FieldValue
                        v-if="row.field"
                        :field="row.field"
                        :value="row.value"
                        :context="context"
                    />
                    <template v-else>—</template>
                </dd>
            </div>
        </dl>
    </div>
</template>
