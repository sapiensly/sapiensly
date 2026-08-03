<script setup lang="ts">
import { computed, inject } from 'vue';
import RuntimeIcon from '../RuntimeIcon.vue';
import { confirmAction } from '../confirm';
import type { ObjectDef } from '../types/manifest';
import { resolveField } from '../types/manifest';
import { type RuntimeAction, useActionExecutor } from '../useActionExecutor';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';
import { runtimeWord } from '../words';
import FieldValue from './FieldValue.vue';
import { type DisplayContext } from './fieldDisplay';

interface DataColumn {
    field_id: string;
    label_override?: string;
}

/**
 * A button per row. This is where a child record's own edit and delete live: a
 * child object usually has no page of its own, so without this a line item
 * could be added and never corrected or removed.
 */
interface ActionColumn {
    id: string;
    type: 'action';
    label: string;
    icon?: string;
    variant?: 'primary' | 'secondary' | 'danger' | 'ghost';
    on_click: RuntimeAction[];
    confirm?: { title: string; message: string };
}

type Column = DataColumn | ActionColumn;

function isAction(column: Column): column is ActionColumn {
    return (column as ActionColumn).type === 'action';
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
    props.block.columns.map((c, i) => {
        if (isAction(c)) {
            return { key: c.id, action: c, field: undefined, label: '' };
        }

        const field = resolveField(object.value, c.field_id);
        return {
            key: c.field_id || `col_${i}`,
            action: undefined,
            field,
            label: c.label_override ?? field?.name ?? c.field_id,
        };
    }),
);

const appSlug = inject<string>('appSlug', '');
const { execute } = useActionExecutor();

async function runRowAction(column: ActionColumn, row: RowData): Promise<void> {
    if (column.confirm) {
        const ok = await confirmAction({
            title: column.confirm.title,
            message: column.confirm.message,
            locale: props.locale,
            danger: column.variant === 'danger',
        });
        if (!ok) return;
    }

    await execute(column.on_click, { appSlug, row });
}

const actionClass: Record<string, string> = {
    primary: 'bg-accent-blue text-white hover:bg-accent-blue-hover',
    secondary: 'border border-medium hover:bg-surface-hover',
    danger: 'text-red-500 hover:bg-red-500/10',
    ghost: 'hover:bg-surface-hover',
};

const rows = computed<RowData[]>(() => props.data?.rows ?? []);

function contextFor(row: RowData): DisplayContext {
    return {
        locale: props.locale,
        defaultCurrency: props.defaultCurrency,
        labels: row.labels,
        labelsTrashed: row.labels_trashed,
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
                        <button
                            v-if="col.action"
                            type="button"
                            :data-sp-row-action="col.action.label"
                            :class="[
                                'inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs transition-colors',
                                actionClass[col.action.variant ?? 'ghost'],
                            ]"
                            @click="runRowAction(col.action, row)"
                        >
                            <RuntimeIcon
                                v-if="col.action.icon"
                                :name="col.action.icon"
                                class="size-3.5"
                            />
                            {{ col.action.label }}
                        </button>
                        <FieldValue
                            v-else-if="col.field"
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
