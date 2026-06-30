<script setup lang="ts">
/**
 * Edits a record.* trigger's `filter` as a flat list of conditions combined
 * with ALL (and) / ANY (or). This is the 90% case; arbitrary nesting and
 * `not` aren't offered here (author those via chat/JSON). `related` and
 * `value_expression` are rejected for trigger filters by the backend
 * validator, so they never appear — and if a filter we can't represent flatly
 * arrives, we show a read-only notice instead of clobbering it.
 *
 * Emits the manifest `filter_expression`: a single leaf when there's one
 * condition, an {op, conditions} group when there are several, or `undefined`
 * to drop the filter entirely.
 */

import { Plus, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

interface ManifestField {
    id: string;
    slug: string;
    name: string;
    type: string;
    options?: { value: string; label?: string }[];
}

/** UI row: a flat leaf. `value2` is only used by `between`. */
interface Row {
    field_id: string;
    op: string;
    value: string;
    value2: string;
}

const props = defineProps<{
    modelValue?: unknown;
    fields: ManifestField[];
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: unknown): void;
}>();

const { t } = useI18n();

const TEXT_OPS = [
    'eq',
    'neq',
    'contains',
    'starts_with',
    'ends_with',
    'in',
    'not_in',
    'is_null',
    'is_not_null',
];
const NUMERIC_OPS = [
    'eq',
    'neq',
    'gt',
    'gte',
    'lt',
    'lte',
    'between',
    'is_null',
    'is_not_null',
];
const SELECT_OPS = ['eq', 'neq', 'in', 'not_in', 'is_null', 'is_not_null'];

function opsForType(type: string): string[] {
    switch (type) {
        case 'number':
        case 'currency':
        case 'rating':
        case 'slider':
        case 'date':
        case 'datetime':
            return NUMERIC_OPS;
        case 'single_select':
            return SELECT_OPS;
        case 'multi_select':
            return ['contains', 'in', 'not_in', 'is_null', 'is_not_null'];
        case 'boolean':
            return ['eq', 'is_null', 'is_not_null'];
        default:
            return TEXT_OPS;
    }
}

const fieldById = computed(() => new Map(props.fields.map((f) => [f.id, f])));

function fieldType(fieldId: string): string {
    return fieldById.value.get(fieldId)?.type ?? 'string';
}

function needsValue(op: string): boolean {
    return op !== 'is_null' && op !== 'is_not_null';
}

function isMulti(op: string): boolean {
    return op === 'in' || op === 'not_in';
}

function htmlInputType(type: string): string {
    switch (type) {
        case 'number':
        case 'currency':
        case 'rating':
        case 'slider':
            return 'number';
        case 'date':
            return 'date';
        case 'datetime':
            return 'datetime-local';
        default:
            return 'text';
    }
}

// ---- parse incoming modelValue → editable rows + mode ----
const mode = ref<'and' | 'or'>('and');
const rows = ref<Row[]>([]);
// True when the incoming filter uses shapes this flat editor can't represent
// (not / nested groups). We then render a notice instead of the builder.
const unsupported = ref(false);

function leafToRow(leaf: Record<string, unknown>): Row {
    const op = String(leaf.op ?? 'eq');
    const value = leaf.value;
    let v = '';
    let v2 = '';
    if (Array.isArray(value)) {
        if (op === 'between') {
            v = value[0] != null ? String(value[0]) : '';
            v2 = value[1] != null ? String(value[1]) : '';
        } else {
            v = value.map((x) => String(x)).join(', ');
        }
    } else if (value != null) {
        v = String(value);
    }
    return { field_id: String(leaf.field_id ?? ''), op, value: v, value2: v2 };
}

function isLeaf(node: Record<string, unknown>): boolean {
    return typeof node.field_id === 'string' && node.op !== 'related';
}

function parse(value: unknown): void {
    unsupported.value = false;
    if (!value || typeof value !== 'object') {
        mode.value = 'and';
        rows.value = [];
        return;
    }
    const node = value as Record<string, unknown>;
    const op = node.op;

    if (op === 'and' || op === 'or') {
        const conditions = Array.isArray(node.conditions)
            ? node.conditions
            : [];
        if (!conditions.every((c) => c && typeof c === 'object' && isLeaf(c))) {
            unsupported.value = true;
            return;
        }
        mode.value = op;
        rows.value = conditions.map((c) =>
            leafToRow(c as Record<string, unknown>),
        );
        return;
    }

    if (isLeaf(node)) {
        mode.value = 'and';
        rows.value = [leafToRow(node)];
        return;
    }

    // not / related / anything else → can't edit flatly.
    unsupported.value = true;
}

// ---- serialize rows + mode → filter_expression (or undefined) ----
function rowToLeaf(row: Row): Record<string, unknown> | null {
    if (!row.field_id) return null;
    const leaf: Record<string, unknown> = {
        op: row.op,
        field_id: row.field_id,
    };
    if (!needsValue(row.op)) {
        return leaf;
    }
    if (row.op === 'between') {
        leaf.value = [row.value, row.value2];
        return leaf;
    }
    if (isMulti(row.op)) {
        leaf.value = row.value
            .split(',')
            .map((s) => s.trim())
            .filter((s) => s !== '');
        return leaf;
    }
    leaf.value = row.value;
    return leaf;
}

function serialize(): unknown {
    const leaves = rows.value
        .map((r) => rowToLeaf(r))
        .filter((l): l is Record<string, unknown> => l !== null);
    if (leaves.length === 0) return undefined;
    if (leaves.length === 1) return leaves[0];
    return { op: mode.value, conditions: leaves };
}

let echo = '';
function commit(): void {
    const next = serialize();
    echo = JSON.stringify(next ?? null);
    emit('update:modelValue', next);
}

// Re-parse only when the incoming value changes from outside (e.g. switching
// nodes), not when it's the value we just emitted.
watch(
    () => props.modelValue,
    (value) => {
        if (JSON.stringify(value ?? null) === echo) return;
        parse(value);
    },
    { immediate: true, deep: true },
);

// ---- row mutations ----
function addRow(): void {
    const firstField = props.fields[0];
    rows.value.push({
        field_id: firstField?.id ?? '',
        op: firstField ? opsForType(firstField.type)[0] : 'eq',
        value: '',
        value2: '',
    });
    commit();
}

function removeRow(index: number): void {
    rows.value.splice(index, 1);
    commit();
}

function onFieldChange(row: Row): void {
    // Reset the op if the new field type doesn't support the current one.
    const ops = opsForType(fieldType(row.field_id));
    if (!ops.includes(row.op)) {
        row.op = ops[0];
    }
    commit();
}

function clearAdvanced(): void {
    unsupported.value = false;
    mode.value = 'and';
    rows.value = [];
    commit();
}

const inputClass =
    'h-8 w-full rounded-md border border-medium bg-surface px-2 text-sm text-ink';
</script>

<template>
    <div class="space-y-2">
        <span class="text-sm text-ink-muted">{{
            t('apps.builder.workflows.panel.filter_heading')
        }}</span>

        <!-- A filter shape we can't represent flatly (not / nested groups). -->
        <div
            v-if="unsupported"
            class="space-y-2 rounded-md border border-dashed border-soft bg-surface p-2 text-xs text-ink-muted"
        >
            <p>{{ t('apps.builder.workflows.panel.filter_advanced') }}</p>
            <button
                type="button"
                class="rounded-pill border border-medium px-2 py-0.5 text-xs text-ink-muted transition-colors hover:border-accent-blue/40"
                @click="clearAdvanced"
            >
                {{ t('apps.builder.workflows.panel.filter_clear') }}
            </button>
        </div>

        <template v-else>
            <p v-if="rows.length === 0" class="text-xs text-ink-subtle">
                {{ t('apps.builder.workflows.panel.filter_empty') }}
            </p>

            <!-- ALL / ANY combiner — only meaningful with 2+ conditions. -->
            <div
                v-if="rows.length > 1"
                class="flex items-center gap-2 text-xs text-ink-muted"
            >
                <span>{{
                    t('apps.builder.workflows.panel.filter_match')
                }}</span>
                <select
                    v-model="mode"
                    class="h-7 rounded-md border border-medium bg-surface px-1.5 text-xs text-ink"
                    @change="commit"
                >
                    <option value="and">
                        {{ t('apps.builder.workflows.panel.filter_all') }}
                    </option>
                    <option value="or">
                        {{ t('apps.builder.workflows.panel.filter_any') }}
                    </option>
                </select>
            </div>

            <div
                v-for="(row, index) in rows"
                :key="index"
                class="space-y-1 rounded-md border border-soft bg-navy/40 p-2"
            >
                <div class="flex items-center gap-1">
                    <!-- Field -->
                    <select
                        v-model="row.field_id"
                        :class="inputClass"
                        @change="onFieldChange(row)"
                    >
                        <option v-for="f in fields" :key="f.id" :value="f.id">
                            {{ f.name }}
                        </option>
                    </select>
                    <!-- Operator -->
                    <select
                        v-model="row.op"
                        :class="inputClass"
                        @change="commit"
                    >
                        <option
                            v-for="op in opsForType(fieldType(row.field_id))"
                            :key="op"
                            :value="op"
                        >
                            {{ t(`apps.builder.workflows.filter_op.${op}`) }}
                        </option>
                    </select>
                    <button
                        type="button"
                        class="shrink-0 rounded-md p-1 text-ink-subtle transition-colors hover:text-sp-danger"
                        :title="t('apps.builder.workflows.panel.filter_remove')"
                        @click="removeRow(index)"
                    >
                        <Trash2 class="size-3.5" />
                    </button>
                </div>

                <!-- Value(s) — depends on field type + operator. -->
                <template v-if="needsValue(row.op)">
                    <!-- between: two inputs -->
                    <div
                        v-if="row.op === 'between'"
                        class="flex items-center gap-1"
                    >
                        <input
                            v-model="row.value"
                            :type="htmlInputType(fieldType(row.field_id))"
                            :class="inputClass"
                            @input="commit"
                        />
                        <span class="text-xs text-ink-subtle">–</span>
                        <input
                            v-model="row.value2"
                            :type="htmlInputType(fieldType(row.field_id))"
                            :class="inputClass"
                            @input="commit"
                        />
                    </div>
                    <!-- boolean: true/false -->
                    <select
                        v-else-if="fieldType(row.field_id) === 'boolean'"
                        v-model="row.value"
                        :class="inputClass"
                        @change="commit"
                    >
                        <option value="true">{{ t('common.yes') }}</option>
                        <option value="false">{{ t('common.no') }}</option>
                    </select>
                    <!-- single/multi select with a single-value op: option dropdown -->
                    <select
                        v-else-if="
                            !isMulti(row.op) &&
                            (fieldType(row.field_id) === 'single_select' ||
                                fieldType(row.field_id) === 'multi_select')
                        "
                        v-model="row.value"
                        :class="inputClass"
                        @change="commit"
                    >
                        <option
                            v-for="opt in fieldById.get(row.field_id)
                                ?.options ?? []"
                            :key="opt.value"
                            :value="opt.value"
                        >
                            {{ opt.label ?? opt.value }}
                        </option>
                    </select>
                    <!-- in / not_in: comma-separated list -->
                    <input
                        v-else-if="isMulti(row.op)"
                        v-model="row.value"
                        type="text"
                        :class="inputClass"
                        :placeholder="
                            t(
                                'apps.builder.workflows.panel.filter_list_placeholder',
                            )
                        "
                        @input="commit"
                    />
                    <!-- scalar -->
                    <input
                        v-else
                        v-model="row.value"
                        :type="htmlInputType(fieldType(row.field_id))"
                        :class="inputClass"
                        @input="commit"
                    />
                </template>
            </div>

            <button
                type="button"
                class="inline-flex items-center gap-1 rounded-pill border border-medium px-2 py-0.5 text-xs text-ink-muted transition-colors hover:border-accent-blue/40"
                :disabled="fields.length === 0"
                @click="addRow"
            >
                <Plus class="size-3" />
                {{ t('apps.builder.workflows.panel.filter_add') }}
            </button>
        </template>
    </div>
</template>
