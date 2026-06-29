<script setup lang="ts">
import {
    ArrowLeft,
    ArrowUpDown,
    Calendar,
    CheckSquare,
    ChevronDown,
    ChevronUp,
    Clock,
    Database,
    GitBranch,
    Hash,
    KeyRound,
    Link2,
    List,
    ListChecks,
    Loader2,
    Mail,
    Search,
    Sigma,
    Sparkles,
    Tag,
    Text,
    ToggleLeft,
    Type,
    X,
    Zap,
} from '@lucide/vue';
import axios from 'axios';
import { computed, ref, watch } from 'vue';

interface FieldDef {
    id: string;
    slug: string;
    name: string;
    type: string;
    system?: boolean;
    readonly?: boolean;
    required?: boolean;
    target_object_id?: string;
    cardinality?: string;
}

interface ObjectDef {
    id: string;
    slug: string;
    name: string;
    name_plural?: string;
    fields: FieldDef[];
    system_fields?: FieldDef[];
}

interface WorkflowChip {
    id: string;
    name: string;
    trigger_type: string | null;
}

interface RelationEdge {
    field_id: string;
    name: string | null;
    from_object_id: string;
    from_field_slug: string | null;
    to_object_id: string;
    cardinality: string | null;
    kind: 'belongs_to' | 'has_many';
}

interface SchemaData {
    objects: ObjectDef[];
    record_counts: Record<string, number>;
    relations?: RelationEdge[];
    workflows_by_object: Record<string, WorkflowChip[]>;
}

const props = defineProps<{ schema: SchemaData | null; appId: string }>();

// Drill-down state — selecting an object swaps the grid for a table view.
const selectedObjectId = ref<string | null>(null);
const detail = ref<{
    object: ObjectDef;
    rows: Array<{
        id: string;
        data: Record<string, unknown>;
        sys_created_at: string | null;
        sys_updated_at: string | null;
    }>;
    total: number;
    limit: number;
    offset: number;
    q: string;
    sort_field_id: string;
    sort_dir: 'asc' | 'desc';
} | null>(null);
const detailLoading = ref(false);
const detailError = ref<string | null>(null);

// Per-detail-session search + sort state, kept independent from the response
// payload so the input stays responsive while the request flies.
const searchInput = ref('');
const sortFieldId = ref<string>('sys_created_at');
const sortDir = ref<'asc' | 'desc'>('desc');
const currentOffset = ref(0);
let searchDebounce: ReturnType<typeof setTimeout> | null = null;

async function fetchRecords() {
    if (!selectedObjectId.value) return;
    detailLoading.value = true;
    detailError.value = null;
    try {
        const { data } = await axios.get(
            `/apps/${props.appId}/builder/objects/${selectedObjectId.value}/records`,
            {
                params: {
                    limit: 50,
                    offset: currentOffset.value,
                    q: searchInput.value,
                    sort_field_id: sortFieldId.value,
                    sort_dir: sortDir.value,
                },
            },
        );
        detail.value = data;
    } catch (e) {
        const err = e as {
            response?: { data?: { message?: string } };
            message?: string;
        };
        detailError.value =
            err.response?.data?.message ??
            err.message ??
            'Failed to load records.';
        detail.value = null;
    } finally {
        detailLoading.value = false;
    }
}

function openObject(objectId: string) {
    selectedObjectId.value = objectId;
    // Fresh drill-down — reset every per-session control.
    searchInput.value = '';
    sortFieldId.value = 'sys_created_at';
    sortDir.value = 'desc';
    currentOffset.value = 0;
    resetAggregate();
    fetchRecords();
}

function closeDetail() {
    selectedObjectId.value = null;
    detail.value = null;
    detailError.value = null;
    resetAggregate();
    if (searchDebounce) {
        clearTimeout(searchDebounce);
        searchDebounce = null;
    }
}

// Debounce typing — 300 ms is the sweet spot for "feels instant" without
// firing a request on every keystroke. Search also narrows the aggregation.
watch(searchInput, () => {
    if (searchDebounce) clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
        currentOffset.value = 0;
        fetchRecords();
        if (aggOpen.value && aggValid.value) runAggregate();
    }, 300);
});

function clearSearch() {
    searchInput.value = '';
    // The watcher above will fire and refetch; nothing else to do.
}

/**
 * Tri-state sort: click a header to enable asc on that column. Clicking the
 * same column again flips to desc. A third click on the same column clears
 * the explicit sort and falls back to the default sys_created_at desc.
 */
function toggleSort(fieldId: string) {
    if (sortFieldId.value !== fieldId) {
        sortFieldId.value = fieldId;
        sortDir.value = 'asc';
    } else if (sortDir.value === 'asc') {
        sortDir.value = 'desc';
    } else {
        sortFieldId.value = 'sys_created_at';
        sortDir.value = 'desc';
    }
    currentOffset.value = 0;
    fetchRecords();
}

const pageStart = computed(() => (detail.value ? detail.value.offset + 1 : 0));
const pageEnd = computed(() =>
    detail.value
        ? Math.min(
              detail.value.offset + detail.value.rows.length,
              detail.value.total,
          )
        : 0,
);
const canPrev = computed(() =>
    detail.value ? detail.value.offset > 0 : false,
);
const canNext = computed(() =>
    detail.value
        ? detail.value.offset + detail.value.rows.length < detail.value.total
        : false,
);

function nextPage() {
    if (!detail.value) return;
    currentOffset.value = detail.value.offset + detail.value.limit;
    fetchRecords();
}

function prevPage() {
    if (!detail.value) return;
    currentOffset.value = Math.max(0, detail.value.offset - detail.value.limit);
    fetchRecords();
}

// ── Quick aggregation panel ────────────────────────────────────────────────
const NUMERIC_AGG_TYPES = [
    'number',
    'currency',
    'rating',
    'slider',
    'formula',
    'lookup',
    'rollup',
];
const NON_GROUPABLE_TYPES = [
    'formula',
    'lookup',
    'rollup',
    'relation',
    'multi_select',
    'file',
    'rich_text',
    'date_range',
];

const aggOpen = ref(false);
const aggFn = ref<'count' | 'sum' | 'avg' | 'min' | 'max'>('count');
const aggFieldId = ref<string>('');
const aggGroupBy = ref<string>('');
const aggBucket = ref<string>('month');
const aggLoading = ref(false);
const aggError = ref<string | null>(null);
const aggResult = ref<{
    value?: number;
    groups?: Array<{ group: unknown; value: number }>;
} | null>(null);

const numericFields = computed<FieldDef[]>(() =>
    (detail.value?.object.fields ?? []).filter((f) =>
        NUMERIC_AGG_TYPES.includes(f.type),
    ),
);
const groupByFields = computed<FieldDef[]>(() =>
    (detail.value?.object.fields ?? []).filter(
        (f) => !NON_GROUPABLE_TYPES.includes(f.type),
    ),
);
const groupByIsDate = computed(() => {
    const f = detail.value?.object.fields.find(
        (x) => x.id === aggGroupBy.value,
    );
    return f?.type === 'date' || f?.type === 'datetime';
});
const aggValid = computed(
    () => aggFn.value === 'count' || aggFieldId.value !== '',
);

function resetAggregate() {
    aggOpen.value = false;
    aggFn.value = 'count';
    aggFieldId.value = '';
    aggGroupBy.value = '';
    aggBucket.value = 'month';
    aggResult.value = null;
    aggError.value = null;
}

function toggleAggPanel() {
    aggOpen.value = !aggOpen.value;
    if (aggOpen.value && aggValid.value && !aggResult.value) runAggregate();
}

async function runAggregate() {
    if (!selectedObjectId.value || !aggValid.value) return;
    aggLoading.value = true;
    aggError.value = null;
    try {
        const params: Record<string, string> = { aggregation: aggFn.value };
        if (aggFn.value !== 'count') params.field_id = aggFieldId.value;
        if (aggGroupBy.value) params.group_by = aggGroupBy.value;
        if (aggGroupBy.value && groupByIsDate.value && aggBucket.value)
            params.bucket = aggBucket.value;
        if (searchInput.value) params.q = searchInput.value;

        const { data } = await axios.get(
            `/apps/${props.appId}/builder/objects/${selectedObjectId.value}/aggregate`,
            { params },
        );
        aggResult.value = data;
    } catch (e) {
        const err = e as {
            response?: { data?: { message?: string } };
            message?: string;
        };
        aggError.value =
            err.response?.data?.message ?? err.message ?? 'Aggregation failed.';
        aggResult.value = null;
    } finally {
        aggLoading.value = false;
    }
}

// Auto-run when any control changes while the panel is open and the inputs make
// sense (count needs no field; sum/avg/min/max need one).
watch([aggFn, aggFieldId, aggGroupBy, aggBucket], () => {
    if (aggOpen.value && aggValid.value) runAggregate();
});

const aggGroupMax = computed(() => {
    const groups = aggResult.value?.groups ?? [];
    return (
        groups.reduce(
            (m, g) => Math.max(m, Math.abs(Number(g.value) || 0)),
            0,
        ) || 1
    );
});

function aggFnLabel(fn: string): string {
    return (
        { count: 'Count', sum: 'Sum', avg: 'Average', min: 'Min', max: 'Max' }[
            fn
        ] ?? fn
    );
}

function formatAggValue(v: unknown): string {
    const n = Number(v);
    if (Number.isNaN(n)) return String(v ?? '—');
    return n.toLocaleString(undefined, { maximumFractionDigits: 2 });
}

function formatGroupKey(g: unknown): string {
    if (g === null || g === undefined || g === '') return '(empty)';
    return String(g);
}

function formatCell(value: unknown, type: string): string {
    if (value === null || value === undefined || value === '') return '—';
    if (type === 'boolean') return value ? '✓' : '✗';
    if (type === 'date' || type === 'datetime') {
        try {
            const d = new Date(String(value));
            if (Number.isNaN(d.getTime())) return String(value);
            return type === 'date'
                ? d.toLocaleDateString()
                : d.toLocaleString();
        } catch {
            return String(value);
        }
    }
    if (type === 'multi_select' && Array.isArray(value)) {
        return value.join(', ');
    }
    if (type === 'rating') {
        const n = Number(value);
        return '★'.repeat(Math.max(0, Math.min(10, Math.round(n)))) + ` ${n}`;
    }
    if (type === 'slider') {
        return String(value);
    }
    if (type === 'date_range' && typeof value === 'object') {
        const r = value as { from?: string; to?: string };
        const fmt = (s?: string) => {
            if (!s) return '—';
            try {
                return new Date(s).toLocaleDateString();
            } catch {
                return s;
            }
        };
        return `${fmt(r.from)} → ${fmt(r.to)}`;
    }
    if (type === 'file' && typeof value === 'object') {
        const f = value as { original_name?: string; size_bytes?: number };
        const size = f.size_bytes ?? 0;
        const sizeStr =
            size < 1024
                ? `${size} B`
                : size < 1024 * 1024
                  ? `${(size / 1024).toFixed(0)} KB`
                  : `${(size / 1024 / 1024).toFixed(1)} MB`;
        return `📎 ${f.original_name ?? '(unnamed)'} · ${sizeStr}`;
    }
    if (type === 'rich_text' && typeof value === 'string') {
        // For the schema drill-down we collapse rich text to plain so the
        // table row height stays consistent. Click the row to see the full
        // formatting in the runtime preview.
        const stripped = value
            .replace(/<[^>]+>/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
        return stripped.length > 120
            ? stripped.slice(0, 117) + '…'
            : stripped || '—';
    }
    if (typeof value === 'object') {
        return JSON.stringify(value);
    }
    return String(value);
}

const ICON_FOR_TYPE: Record<string, unknown> = {
    string: Type,
    long_text: Text,
    number: Hash,
    currency: Hash,
    boolean: ToggleLeft,
    date: Calendar,
    datetime: Clock,
    single_select: Tag,
    multi_select: ListChecks,
    relation: Link2,
    formula: Sigma,
    lookup: KeyRound,
    rollup: List,
    email: Mail,
};

function iconForType(type: string) {
    return ICON_FOR_TYPE[type] ?? CheckSquare;
}

function targetObjectName(targetId: string | undefined): string | null {
    if (!targetId) return null;
    return props.schema?.objects.find((o) => o.id === targetId)?.name ?? null;
}

function cardinalityShort(c: string | null | undefined): string {
    if (!c) return '';
    return (
        {
            one_to_one: '1:1',
            many_to_one: 'N:1',
            one_to_many: '1:N',
            many_to_many: 'N:N',
        }[c] ?? c
    );
}

// Relations grouped by the object they originate from, each annotated with the
// target object's display name. Drives the per-object "Relations" section.
const relationsByObject = computed<
    Record<string, Array<RelationEdge & { targetName: string }>>
>(() => {
    const map: Record<
        string,
        Array<RelationEdge & { targetName: string }>
    > = {};
    for (const e of props.schema?.relations ?? []) {
        (map[e.from_object_id] ??= []).push({
            ...e,
            targetName: targetObjectName(e.to_object_id) ?? e.to_object_id,
        });
    }
    return map;
});

function triggerLabel(t: string | null): string {
    if (!t) return '';
    return (
        {
            'record.created': 'on create',
            'record.updated': 'on update',
            'record.deleted': 'on delete',
            manual: 'manual',
        }[t] ?? t
    );
}
</script>

<template>
    <div
        v-if="!schema || schema.objects.length === 0"
        class="flex h-full flex-col items-center justify-center gap-2 p-8 text-center"
    >
        <Database class="size-8 text-ink-subtle" />
        <p class="text-sm text-ink-muted">No data model yet.</p>
        <p class="max-w-xs text-xs text-ink-subtle">
            Ask the Builder to add an object — e.g. "create a Clientes object
            with nombre and email".
        </p>
    </div>

    <!-- Drill-down: one object's records as a table. -->
    <div v-else-if="selectedObjectId" class="flex h-full flex-col">
        <header
            class="flex flex-wrap items-center justify-between gap-3 border-b border-soft px-5 py-3"
        >
            <div class="flex min-w-0 items-center gap-3">
                <button
                    type="button"
                    @click="closeDetail"
                    class="inline-flex items-center gap-1 rounded-pill border border-medium bg-surface px-2.5 py-1 text-[11px] text-ink-muted transition-colors hover:border-strong hover:text-ink"
                >
                    <ArrowLeft class="size-3" />
                    Back
                </button>
                <Database class="size-3.5 shrink-0 text-accent-blue" />
                <h3 class="truncate text-sm font-semibold text-ink">
                    {{ detail?.object.name ?? '…' }}
                </h3>
                <code
                    v-if="detail"
                    class="truncate text-[10px] text-ink-subtle"
                    >{{ detail.object.slug }}</code
                >
                <span
                    v-if="detail"
                    class="inline-flex shrink-0 items-center rounded-pill border border-medium bg-surface px-2 py-0.5 text-[10px] font-medium text-ink-muted"
                >
                    {{ detail.total.toLocaleString() }} rows
                </span>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="button"
                    @click="toggleAggPanel"
                    class="inline-flex items-center gap-1 rounded-pill border px-2.5 py-1 text-[11px] transition-colors"
                    :class="
                        aggOpen
                            ? 'border-accent-blue/50 bg-accent-blue/10 text-accent-blue'
                            : 'border-medium bg-surface text-ink-muted hover:border-strong hover:text-ink'
                    "
                    title="Quick aggregation"
                >
                    <Sigma class="size-3" />
                    Insights
                </button>
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2 size-3 -translate-y-1/2 text-ink-subtle"
                    />
                    <input
                        v-model="searchInput"
                        type="search"
                        placeholder="Search…"
                        class="h-7 w-44 rounded border border-medium bg-surface pr-7 pl-7 text-[11px] text-ink placeholder:text-ink-subtle focus:border-accent-blue focus:outline-none"
                    />
                    <button
                        v-if="searchInput"
                        type="button"
                        @click="clearSearch"
                        class="absolute top-1/2 right-1.5 -translate-y-1/2 rounded p-0.5 text-ink-subtle hover:text-ink"
                        title="Clear search"
                    >
                        <X class="size-3" />
                    </button>
                </div>
                <div
                    v-if="detail && detail.total > 0"
                    class="flex items-center gap-2 text-[11px] text-ink-muted"
                >
                    <span
                        >{{ pageStart }}–{{ pageEnd }} of
                        {{ detail.total }}</span
                    >
                    <button
                        type="button"
                        @click="prevPage"
                        :disabled="!canPrev || detailLoading"
                        class="rounded border border-medium bg-surface px-2 py-0.5 text-ink-muted transition-colors enabled:hover:text-ink disabled:opacity-40"
                    >
                        Prev
                    </button>
                    <button
                        type="button"
                        @click="nextPage"
                        :disabled="!canNext || detailLoading"
                        class="rounded border border-medium bg-surface px-2 py-0.5 text-ink-muted transition-colors enabled:hover:text-ink disabled:opacity-40"
                    >
                        Next
                    </button>
                </div>
            </div>
        </header>

        <!-- Quick aggregation: count / sum / avg / min / max, optionally grouped. -->
        <section
            v-if="aggOpen && detail"
            class="border-b border-soft bg-navy/40 px-5 py-3"
        >
            <div class="flex flex-wrap items-center gap-2 text-[11px]">
                <select
                    v-model="aggFn"
                    class="h-7 rounded border border-medium bg-surface px-2 text-[11px] text-ink focus:border-accent-blue focus:outline-none"
                >
                    <option value="count">Count</option>
                    <option value="sum">Sum</option>
                    <option value="avg">Average</option>
                    <option value="min">Min</option>
                    <option value="max">Max</option>
                </select>

                <select
                    v-if="aggFn !== 'count'"
                    v-model="aggFieldId"
                    class="h-7 rounded border border-medium bg-surface px-2 text-[11px] text-ink focus:border-accent-blue focus:outline-none"
                >
                    <option value="" disabled>of field…</option>
                    <option
                        v-for="f in numericFields"
                        :key="f.id"
                        :value="f.id"
                    >
                        {{ f.name }}
                    </option>
                </select>

                <span class="text-ink-subtle">by</span>
                <select
                    v-model="aggGroupBy"
                    class="h-7 rounded border border-medium bg-surface px-2 text-[11px] text-ink focus:border-accent-blue focus:outline-none"
                >
                    <option value="">(no grouping)</option>
                    <option
                        v-for="f in groupByFields"
                        :key="f.id"
                        :value="f.id"
                    >
                        {{ f.name }}
                    </option>
                </select>

                <select
                    v-if="aggGroupBy && groupByIsDate"
                    v-model="aggBucket"
                    class="h-7 rounded border border-medium bg-surface px-2 text-[11px] text-ink focus:border-accent-blue focus:outline-none"
                >
                    <option value="day">by day</option>
                    <option value="week">by week</option>
                    <option value="month">by month</option>
                    <option value="quarter">by quarter</option>
                    <option value="year">by year</option>
                </select>

                <Loader2
                    v-if="aggLoading"
                    class="size-3.5 animate-spin text-ink-muted"
                />
                <span
                    v-if="aggFn !== 'count' && numericFields.length === 0"
                    class="text-amber-400/80"
                    >No numeric fields to aggregate.</span
                >
            </div>

            <div v-if="aggError" class="mt-2 text-[11px] text-red-400">
                {{ aggError }}
            </div>

            <!-- Scalar result. -->
            <div
                v-else-if="aggResult && aggResult.value !== undefined"
                class="mt-3 flex items-baseline gap-2"
            >
                <span class="text-2xl font-semibold text-ink">{{
                    formatAggValue(aggResult.value)
                }}</span>
                <span class="text-[11px] text-ink-subtle"
                    >{{ aggFnLabel(aggFn)
                    }}{{
                        aggFn !== 'count'
                            ? ' of ' +
                              (numericFields.find((f) => f.id === aggFieldId)
                                  ?.name ?? '')
                            : ''
                    }}</span
                >
            </div>

            <!-- Grouped result as labelled bars. -->
            <div
                v-else-if="aggResult && aggResult.groups"
                class="mt-3 space-y-1.5"
            >
                <p
                    v-if="aggResult.groups.length === 0"
                    class="text-[11px] text-ink-subtle"
                >
                    No data to group.
                </p>
                <div
                    v-for="(g, i) in aggResult.groups"
                    :key="i"
                    class="flex items-center gap-2"
                >
                    <span
                        class="w-32 shrink-0 truncate text-[11px] text-ink-muted"
                        :title="formatGroupKey(g.group)"
                        >{{ formatGroupKey(g.group) }}</span
                    >
                    <div
                        class="relative h-4 flex-1 overflow-hidden rounded-sp-sm bg-surface"
                    >
                        <div
                            class="absolute inset-y-0 left-0 rounded-sp-sm bg-accent-blue/30"
                            :style="{
                                width:
                                    Math.max(
                                        2,
                                        (Math.abs(Number(g.value) || 0) /
                                            aggGroupMax) *
                                            100,
                                    ) + '%',
                            }"
                        />
                    </div>
                    <span
                        class="w-20 shrink-0 text-right font-mono text-[11px] text-ink"
                        >{{ formatAggValue(g.value) }}</span
                    >
                </div>
            </div>
        </section>

        <div
            v-if="detailLoading"
            class="flex flex-1 items-center justify-center gap-2 text-xs text-ink-muted"
        >
            <Loader2 class="size-4 animate-spin" />
            Loading records…
        </div>

        <div
            v-else-if="detailError"
            class="m-5 rounded-sp-sm border border-dashed border-red-400/40 bg-red-500/5 p-4 text-xs text-red-400"
        >
            {{ detailError }}
        </div>

        <div v-else-if="detail" class="flex-1 overflow-auto">
            <table v-if="detail.rows.length > 0" class="w-full text-xs">
                <thead
                    class="sticky top-0 z-10 border-b border-soft bg-navy text-[10px] tracking-wider text-ink-subtle uppercase"
                >
                    <tr>
                        <th class="px-3 py-2 text-left font-medium">ID</th>
                        <th
                            v-for="f in detail.object.fields"
                            :key="f.id"
                            class="px-3 py-2 text-left font-medium"
                        >
                            <button
                                type="button"
                                @click="toggleSort(f.id)"
                                class="group inline-flex items-center gap-1 text-left transition-colors hover:text-ink"
                            >
                                {{ f.name }}
                                <span
                                    class="font-mono text-[9px] text-ink-subtle/70 normal-case"
                                    >{{ f.type }}</span
                                >
                                <ChevronUp
                                    v-if="
                                        sortFieldId === f.id &&
                                        sortDir === 'asc'
                                    "
                                    class="size-3 text-accent-blue"
                                />
                                <ChevronDown
                                    v-else-if="
                                        sortFieldId === f.id &&
                                        sortDir === 'desc'
                                    "
                                    class="size-3 text-accent-blue"
                                />
                                <ArrowUpDown
                                    v-else
                                    class="size-2.5 text-ink-subtle/60 opacity-0 transition-opacity group-hover:opacity-100"
                                />
                            </button>
                        </th>
                        <th class="px-3 py-2 text-left font-medium">
                            <button
                                type="button"
                                @click="toggleSort('sys_created_at')"
                                class="group inline-flex items-center gap-1 text-left transition-colors hover:text-ink"
                            >
                                Created
                                <ChevronUp
                                    v-if="
                                        sortFieldId === 'sys_created_at' &&
                                        sortDir === 'asc'
                                    "
                                    class="size-3 text-accent-blue"
                                />
                                <ChevronDown
                                    v-else-if="
                                        sortFieldId === 'sys_created_at' &&
                                        sortDir === 'desc'
                                    "
                                    class="size-3 text-accent-blue"
                                />
                                <ArrowUpDown
                                    v-else
                                    class="size-2.5 text-ink-subtle/60 opacity-0 transition-opacity group-hover:opacity-100"
                                />
                            </button>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in detail.rows"
                        :key="row.id"
                        class="border-b border-soft/50 transition-colors hover:bg-white/[0.02]"
                    >
                        <td
                            class="px-3 py-2 align-top font-mono text-[10px] text-ink-subtle"
                        >
                            {{ row.id }}
                        </td>
                        <td
                            v-for="f in detail.object.fields"
                            :key="f.id"
                            class="max-w-xs truncate px-3 py-2 align-top text-ink"
                            :title="formatCell(row.data[f.slug], f.type)"
                        >
                            {{ formatCell(row.data[f.slug], f.type) }}
                        </td>
                        <td
                            class="px-3 py-2 align-top font-mono text-[10px] whitespace-nowrap text-ink-subtle"
                        >
                            {{
                                row.sys_created_at
                                    ? new Date(
                                          row.sys_created_at,
                                      ).toLocaleString()
                                    : '—'
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
            <div
                v-else
                class="flex h-full flex-col items-center justify-center gap-2 p-8 text-center"
            >
                <Database class="size-8 text-ink-subtle" />
                <template v-if="detail.q">
                    <p class="text-sm text-ink-muted">
                        No records match "{{ detail.q }}".
                    </p>
                    <button
                        type="button"
                        @click="clearSearch"
                        class="text-xs text-accent-blue hover:underline"
                    >
                        Clear search
                    </button>
                </template>
                <template v-else>
                    <p class="text-sm text-ink-muted">No records yet.</p>
                    <p class="max-w-xs text-xs text-ink-subtle">
                        Add some via the runtime forms or via the Builder.
                    </p>
                </template>
            </div>
        </div>
    </div>

    <div v-else class="h-full overflow-auto p-5">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <article
                v-for="obj in schema.objects"
                :key="obj.id"
                class="rounded-sp-sm border border-soft bg-navy transition-colors hover:border-medium"
            >
                <button
                    type="button"
                    @click="openObject(obj.id)"
                    class="group flex w-full items-center justify-between gap-3 border-b border-soft px-4 py-3 text-left transition-colors hover:bg-white/[0.03]"
                    :title="`View ${obj.name} records`"
                >
                    <div class="flex min-w-0 items-center gap-2">
                        <Database class="size-3.5 shrink-0 text-accent-blue" />
                        <h3 class="truncate text-sm font-semibold text-ink">
                            {{ obj.name }}
                        </h3>
                        <code class="truncate text-[10px] text-ink-subtle">{{
                            obj.slug
                        }}</code>
                    </div>
                    <span
                        class="inline-flex shrink-0 items-center rounded-pill border border-medium bg-surface px-2 py-0.5 text-[10px] font-medium text-ink-muted transition-colors group-hover:text-accent-blue"
                    >
                        {{
                            (schema.record_counts[obj.id] ?? 0).toLocaleString()
                        }}
                        rows →
                    </span>
                </button>

                <ul class="divide-y divide-soft">
                    <li
                        v-for="f in obj.fields"
                        :key="f.id"
                        class="flex items-center justify-between gap-3 px-4 py-2"
                    >
                        <div class="flex min-w-0 items-center gap-2">
                            <component
                                :is="iconForType(f.type)"
                                class="size-3 shrink-0 text-ink-subtle"
                            />
                            <span class="truncate text-xs text-ink">{{
                                f.name
                            }}</span>
                            <span
                                v-if="f.required"
                                class="text-[10px] text-amber-400"
                                >*</span
                            >
                        </div>
                        <div class="flex shrink-0 items-center gap-1.5">
                            <span
                                v-if="
                                    f.type === 'relation' && f.target_object_id
                                "
                                class="inline-flex items-center gap-1 rounded-pill bg-accent-blue/10 px-1.5 py-0.5 text-[10px] text-accent-blue"
                            >
                                → {{ targetObjectName(f.target_object_id) }}
                            </span>
                            <span
                                v-else-if="
                                    ['formula', 'lookup', 'rollup'].includes(
                                        f.type,
                                    )
                                "
                                class="inline-flex items-center gap-1 rounded-pill bg-purple-500/10 px-1.5 py-0.5 text-[10px] text-purple-300"
                            >
                                <Sparkles class="size-2.5" />{{ f.type }}
                            </span>
                            <span
                                class="font-mono text-[10px] text-ink-muted"
                                >{{ f.type }}</span
                            >
                        </div>
                    </li>
                </ul>

                <!-- Relations: how this object links to others (belongs_to / has_many). -->
                <div
                    v-if="(relationsByObject[obj.id] ?? []).length > 0"
                    class="border-t border-soft"
                >
                    <div
                        class="px-4 py-1.5 text-[9px] tracking-wider text-ink-subtle uppercase"
                    >
                        Relations
                    </div>
                    <ul class="divide-y divide-soft">
                        <li
                            v-for="rel in relationsByObject[obj.id]"
                            :key="rel.field_id"
                            class="flex items-center justify-between gap-3 px-4 py-2"
                        >
                            <div class="flex min-w-0 items-center gap-2">
                                <component
                                    :is="
                                        rel.kind === 'belongs_to'
                                            ? Link2
                                            : GitBranch
                                    "
                                    class="size-3 shrink-0"
                                    :class="
                                        rel.kind === 'belongs_to'
                                            ? 'text-accent-blue'
                                            : 'text-emerald-300'
                                    "
                                />
                                <span
                                    class="shrink-0 text-[10px] font-medium"
                                    :class="
                                        rel.kind === 'belongs_to'
                                            ? 'text-accent-blue'
                                            : 'text-emerald-300'
                                    "
                                >
                                    {{
                                        rel.kind === 'belongs_to'
                                            ? '→ belongs to'
                                            : '← has many'
                                    }}
                                </span>
                                <span class="truncate text-xs text-ink">{{
                                    rel.targetName
                                }}</span>
                            </div>
                            <div class="flex shrink-0 items-center gap-1.5">
                                <code
                                    v-if="rel.from_field_slug"
                                    class="text-[10px] text-ink-subtle"
                                    >via {{ rel.from_field_slug }}</code
                                >
                                <span
                                    v-if="cardinalityShort(rel.cardinality)"
                                    class="font-mono text-[10px] text-ink-muted"
                                    >{{
                                        cardinalityShort(rel.cardinality)
                                    }}</span
                                >
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- System fields — virtual, always present, separated visually. -->
                <div
                    v-if="obj.system_fields && obj.system_fields.length > 0"
                    class="border-t border-soft"
                >
                    <div
                        class="px-4 py-1.5 text-[9px] tracking-wider text-ink-subtle uppercase"
                    >
                        Virtual
                    </div>
                    <ul class="divide-y divide-soft">
                        <li
                            v-for="f in obj.system_fields"
                            :key="f.id"
                            class="flex items-center justify-between gap-3 px-4 py-2 opacity-70"
                        >
                            <div class="flex min-w-0 items-center gap-2">
                                <component
                                    :is="iconForType(f.type)"
                                    class="size-3 shrink-0 text-ink-subtle"
                                />
                                <code
                                    class="truncate text-[11px] text-ink-muted"
                                    >{{ f.id }}</code
                                >
                            </div>
                            <span
                                class="font-mono text-[10px] text-ink-subtle"
                                >{{ f.type }}</span
                            >
                        </li>
                    </ul>
                </div>

                <!-- Workflows that hook into this object's lifecycle. -->
                <footer
                    v-if="(schema.workflows_by_object[obj.id] ?? []).length > 0"
                    class="flex flex-wrap items-center gap-1.5 border-t border-soft px-4 py-2"
                >
                    <Zap class="size-3 text-accent-blue" />
                    <span
                        v-for="wf in schema.workflows_by_object[obj.id]"
                        :key="wf.id"
                        class="inline-flex items-center gap-1 rounded-pill border border-accent-blue/30 bg-accent-blue/10 px-2 py-0.5 text-[10px] text-accent-blue"
                        :title="wf.trigger_type ?? ''"
                    >
                        <GitBranch class="size-2.5" />
                        {{ wf.name }}
                        <span class="text-ink-subtle">{{
                            triggerLabel(wf.trigger_type)
                        }}</span>
                    </span>
                </footer>
            </article>
        </div>
    </div>
</template>
