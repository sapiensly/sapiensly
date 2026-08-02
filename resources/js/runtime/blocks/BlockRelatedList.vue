<script setup lang="ts">
import { computed } from 'vue';
import type { ObjectDef } from '../types/manifest';
import { resolveField } from '../types/manifest';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';
import { runtimeWord } from '../words';
import FieldValue from './FieldValue.vue';
import { type DisplayContext } from './fieldDisplay';

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
    labels?: Record<string, unknown>;
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
        return {
            key: c.field_id,
            field,
            label: c.label_override ?? field?.name ?? c.field_id,
        };
    }),
);

const rows = computed<RowData[]>(() => props.data?.rows ?? []);

function contextFor(row: RowData): DisplayContext {
    return {
        locale: props.locale,
        defaultCurrency: props.defaultCurrency,
        labels: row.labels,
        objects: props.objects,
    };
}
</script>

<template>
    <div :class="['overflow-hidden rounded-sp-sm border', t.surface]">
        <p
            v-if="block.label"
            :class="[
                'border-b border-soft px-3 py-2 text-[11px] tracking-wider uppercase',
                t.textSubtle,
            ]"
        >
            {{ block.label }}
        </p>

        <p
            v-if="rows.length === 0"
            :class="['px-3 py-6 text-center text-xs', t.textMuted]"
        >
            {{ runtimeWord(locale, 'no_related') }}
        </p>

        <table v-else class="w-full text-left text-sm">
            <thead>
                <tr :class="['border-b border-soft', t.textMuted]">
                    <th
                        v-for="col in columns"
                        :key="col.key"
                        class="px-3 py-2 text-[11px] font-medium tracking-wider uppercase"
                    >
                        {{ col.label }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="row in rows"
                    :key="row.id"
                    class="border-b border-soft/60 last:border-0"
                >
                    <td
                        v-for="col in columns"
                        :key="col.key"
                        :class="['px-3 py-2', t.text]"
                    >
                        <FieldValue
                            v-if="col.field"
                            :field="col.field"
                            :value="row.data[col.field.slug]"
                            :context="contextFor(row)"
                            size="sm"
                        />
                        <template v-else>—</template>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
