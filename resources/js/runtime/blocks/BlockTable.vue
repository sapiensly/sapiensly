<script setup lang="ts">
import { Check, Columns3, Download } from '@lucide/vue';
import DOMPurify from 'dompurify';
import { computed, inject, ref } from 'vue';
import RuntimeIcon from '../RuntimeIcon.vue';
import type {
    BlockTable,
    FieldDef,
    ObjectDef,
    TableBlockData,
} from '../types/manifest';
import { resolveField } from '../types/manifest';
import { useActionExecutor, type RuntimeAction } from '../useActionExecutor';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';
import FieldValue from './FieldValue.vue';
import { type DisplayContext } from './fieldDisplay';

const props = defineProps<{
    block: BlockTable;
    data: TableBlockData | undefined;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

const theme = useRuntimeTheme();
const t = themeTokens(theme);
const { execute } = useActionExecutor();

const appSlug = inject<string>('appSlug', deriveSlugFromUrl());
function deriveSlugFromUrl(): string {
    const m = window.location.pathname.match(/^\/[ra]\/([a-z0-9][a-z0-9_-]*)/);
    return m?.[1] ?? '';
}

const object = computed<ObjectDef | undefined>(() =>
    props.objects.find((o) => o.id === props.block.data_source.object_id),
);

/**
 * Download what this table shows.
 *
 * Offered whenever the table is object-backed and we are on the authenticated
 * runtime — the server re-checks the role's read permission, so this is an
 * affordance rather than a grant, and every existing app gets it without an
 * author having to opt in. Absent on a portal, where a read grant is a licence
 * to browse and not to take the object in one request. The current query string
 * rides along, so the file is the rows on screen, not the whole object.
 */
const exportHref = computed<string | null>(() => {
    if (!object.value?.slug || !appSlug) return null;
    if (typeof window === 'undefined') return null;
    if (!window.location.pathname.startsWith('/r/')) return null;

    const params = new URLSearchParams(window.location.search);
    params.set('format', 'csv');

    return `/r/${appSlug}/objects/${object.value.slug}/export?${params.toString()}`;
});

/**
 * One request, both outcomes: the file when it fits, a prepared export when it
 * does not. Above the direct ceiling the server refuses with 422 rather than
 * handing back a download that might die half-written.
 *
 * Deliberately not a HEAD probe first — a HEAD on the download route would run
 * the whole export and throw the bytes away.
 */
const exportLabel = ref('CSV');

async function onExportClick(event: MouseEvent) {
    if (!object.value?.slug || !appSlug || !exportHref.value) return;
    event.preventDefault();

    exportLabel.value = 'Descargando…';
    const res = await fetch(exportHref.value).catch(() => null);

    if (res?.ok) {
        const url = URL.createObjectURL(await res.blob());
        const a = document.createElement('a');
        a.href = url;
        a.download = `${object.value.slug}.csv`;
        a.click();
        URL.revokeObjectURL(url);
        exportLabel.value = 'CSV';
        return;
    }

    if (res?.status !== 422) {
        exportLabel.value = 'No se pudo';
        return;
    }

    // Too many rows for one request: prepare it instead.
    exportLabel.value = 'Preparando…';
    const queued = await fetch(
        `/r/${appSlug}/objects/${object.value.slug}/export/queue${window.location.search}`,
        {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN':
                    document
                        .querySelector('meta[name="csrf-token"]')
                        ?.getAttribute('content') ?? '',
                Accept: 'application/json',
            },
        },
    ).catch(() => null);

    exportLabel.value = queued?.ok ? 'Preparándose…' : 'No se pudo';
}

interface DataColumn {
    kind: 'data';
    id: string;
    label: string;
    field: FieldDef;
    width?: number;
    hiddenByDefault: boolean;
}

interface ActionColumn {
    kind: 'action';
    id: string;
    label: string;
    icon?: string;
    variant: 'primary' | 'secondary' | 'danger' | 'ghost';
    width?: number;
    on_click: RuntimeAction[];
    confirm?: { title: string; message: string };
}

type Column = DataColumn | ActionColumn;

const allColumns = computed<Column[]>(() =>
    (props.block.columns as Array<Record<string, unknown>>)
        .map((col): Column | null => {
            if (col.type === 'action') {
                return {
                    kind: 'action',
                    id: col.id as string,
                    label: (col.label as string) ?? 'Action',
                    icon: col.icon as string | undefined,
                    variant:
                        (col.variant as ActionColumn['variant']) ?? 'ghost',
                    width: col.width as number | undefined,
                    on_click: (col.on_click as RuntimeAction[]) ?? [],
                    confirm: col.confirm as ActionColumn['confirm'],
                };
            }
            const field = resolveField(
                object.value,
                col.field_id as string | undefined,
            );
            if (!field) return null;
            return {
                kind: 'data',
                id: col.id as string,
                label: (col.label_override as string | undefined) ?? field.name,
                field,
                width: col.width as number | undefined,
                hiddenByDefault: col.hidden_by_default === true,
            };
        })
        .filter((c): c is Column => c !== null),
);

/**
 * Which columns the reader has switched off.
 *
 * A wide object arrives with most of its columns folded away (the manifest says
 * which), because a table 17 columns across wraps every cell to three lines and
 * stops being scannable. Nothing is dropped though — the picker below lists
 * every column, and what the reader chooses is theirs to keep, so it outlives
 * the visit rather than resetting on every navigation.
 */
const storageKey = computed(() => `sp:table-cols:${appSlug}:${props.block.id}`);

function loadHidden(): Set<string> {
    const known = new Set(
        allColumns.value.filter((c) => c.kind === 'data').map((c) => c.id),
    );
    try {
        const raw = window.localStorage.getItem(storageKey.value);
        if (raw !== null) {
            const saved = JSON.parse(raw);
            if (Array.isArray(saved)) {
                // Intersect with what the table currently has: a manifest that
                // dropped or renamed a column must not leave a stale id behind.
                return new Set(
                    saved.filter(
                        (id: unknown): id is string =>
                            typeof id === 'string' && known.has(id),
                    ),
                );
            }
        }
    } catch {
        // A disabled or full localStorage is not a reason to fail to render.
    }
    return new Set(
        allColumns.value
            .filter((c) => c.kind === 'data' && c.hiddenByDefault)
            .map((c) => c.id),
    );
}

const hidden = ref<Set<string>>(loadHidden());

const columns = computed<Column[]>(() =>
    allColumns.value.filter(
        (c) => c.kind === 'action' || !hidden.value.has(c.id),
    ),
);

/** Every data column, shown or not — the picker's menu. */
const hideableColumns = computed<DataColumn[]>(
    () => allColumns.value.filter((c) => c.kind === 'data') as DataColumn[],
);

const pickerOpen = ref(false);

/**
 * Offer the picker only where the manifest folded something away, or where the
 * reader already has. An app whose tables were always meant to show every
 * column gains no control it has no use for.
 */
const showPicker = computed(
    () =>
        hidden.value.size > 0 ||
        hideableColumns.value.some((c) => c.hiddenByDefault),
);

/**
 * The runtime has no string catalogue, and the two chrome strings that predate
 * this one are hardcoded Spanish — wrong in an English app. One word does not
 * justify inventing a framework, but the block already knows its locale, so
 * spend it rather than add a third.
 */
const COLUMNS_WORD: Record<string, string> = {
    en: 'Columns',
    es: 'Columnas',
    pt: 'Colunas',
    fr: 'Colonnes',
};

const pickerLabel = computed(() => {
    const word =
        COLUMNS_WORD[props.locale.slice(0, 2).toLowerCase()] ?? COLUMNS_WORD.en;
    const shown = hideableColumns.value.length - hidden.value.size;

    // The count is the point when something is folded away: without it the
    // table looks complete and the reader never thinks to look.
    return hidden.value.size > 0
        ? `${word} ${shown}/${hideableColumns.value.length}`
        : word;
});

function toggleColumn(id: string): void {
    const next = new Set(hidden.value);
    if (next.has(id)) {
        next.delete(id);
    } else {
        // Never let the reader empty the table entirely.
        if (columns.value.filter((c) => c.kind === 'data').length <= 1) return;
        next.add(id);
    }
    hidden.value = next;
    try {
        window.localStorage.setItem(
            storageKey.value,
            JSON.stringify([...next]),
        );
    } catch {
        // Same as above: the choice just does not survive the visit.
    }
}

async function runRowAction(
    col: ActionColumn,
    row: { id: string; data: Record<string, unknown> },
) {
    if (
        col.confirm &&
        !window.confirm(`${col.confirm.title}\n\n${col.confirm.message}`)
    ) {
        return;
    }
    await execute(col.on_click, { appSlug, row });
}

const variantClass: Record<ActionColumn['variant'], string> = {
    primary: 'bg-accent-blue text-white hover:bg-accent-blue-hover',
    secondary:
        'border border-medium bg-surface text-ink hover:bg-surface-hover',
    danger: 'bg-red-500/15 text-red-400 hover:bg-red-500/25',
    ghost: 'text-ink-muted hover:bg-surface hover:text-ink',
};

const rows = computed(() => props.data?.rows ?? []);

// Client-side pagination over the loaded rows, active only when the block opts in
// via `pagination.page_size`. (The data_source.limit caps how many rows are
// loaded in total; page through those.)
const pageSize = computed(
    () =>
        (props.block as { pagination?: { page_size?: number } }).pagination
            ?.page_size ?? 0,
);
const page = ref(1);
const pageCount = computed(() =>
    pageSize.value > 0
        ? Math.max(1, Math.ceil(rows.value.length / pageSize.value))
        : 1,
);
const pagedRows = computed(() => {
    if (pageSize.value <= 0) return rows.value;
    const start = (Math.min(page.value, pageCount.value) - 1) * pageSize.value;
    return rows.value.slice(start, start + pageSize.value);
});
function goToPage(p: number) {
    page.value = Math.min(Math.max(1, p), pageCount.value);
}

/**
 * A row's display context: the locale plus the server-resolved relation
 * labels, which are per-row rather than per-column.
 */
function contextFor(row: { labels?: Record<string, unknown> }): DisplayContext {
    return {
        locale: props.locale,
        defaultCurrency: props.defaultCurrency,
        labels: row.labels,
        objects: props.objects,
    };
}

/**
 * Browser-side double-sanitisation for rich_text. The server already
 * sanitises on save, but rendering with v-html means we re-purify defensively
 * — cheap insurance against an older record that slipped through.
 */
function richTextCell(value: unknown): string {
    if (typeof value !== 'string' || value === '') return '—';
    return DOMPurify.sanitize(value);
}
</script>

<template>
    <div :class="['overflow-hidden rounded-sp-sm border', t.surface]">
        <div
            v-if="exportHref || showPicker"
            :class="[
                'flex items-center justify-end gap-1 border-b px-3 py-1.5',
                t.divider,
            ]"
        >
            <div v-if="showPicker" class="relative">
                <button
                    type="button"
                    :class="[
                        'inline-flex items-center gap-1.5 rounded-pill px-2.5 py-1 text-[11px] transition-colors hover:bg-surface-hover',
                        t.textMuted,
                    ]"
                    :aria-expanded="pickerOpen"
                    aria-haspopup="true"
                    @click="pickerOpen = !pickerOpen"
                >
                    <Columns3 class="size-3" />
                    {{ pickerLabel }}
                </button>
                <!--
                    Closing by way of a backdrop rather than a document-level
                    listener: the click that dismisses the menu is swallowed
                    here, so it cannot also land on whatever sits underneath.
                -->
                <template v-if="pickerOpen">
                    <div
                        class="fixed inset-0 z-20"
                        @click="pickerOpen = false"
                    />
                    <div
                        :class="[
                            'absolute right-0 z-30 mt-1 max-h-72 min-w-52 overflow-y-auto rounded-sp-sm border py-1 shadow-lg',
                            t.surface,
                        ]"
                        role="menu"
                    >
                        <button
                            v-for="col in hideableColumns"
                            :key="col.id"
                            type="button"
                            role="menuitemcheckbox"
                            :aria-checked="!hidden.has(col.id)"
                            :class="[
                                'flex w-full items-center gap-2 px-3 py-1.5 text-left text-xs transition-colors hover:bg-surface-hover',
                                t.text,
                            ]"
                            @click="toggleColumn(col.id)"
                        >
                            <span
                                :class="[
                                    'flex size-3.5 shrink-0 items-center justify-center rounded-[3px] border',
                                    hidden.has(col.id)
                                        ? 'border-medium'
                                        : 'border-accent-blue bg-accent-blue text-white',
                                ]"
                            >
                                <Check
                                    v-if="!hidden.has(col.id)"
                                    class="size-2.5"
                                />
                            </span>
                            {{ col.label }}
                        </button>
                    </div>
                </template>
            </div>
            <a
                v-if="exportHref"
                :href="exportHref"
                :class="[
                    'inline-flex items-center gap-1.5 rounded-pill px-2.5 py-1 text-[11px] transition-opacity',
                    t.textMuted,
                ]"
                title="Descargar CSV"
                @click="onExportClick"
            >
                <Download class="size-3" />
                {{ exportLabel }}
            </a>
        </div>
        <table class="w-full border-collapse text-sm">
            <thead>
                <tr :class="['border-b', t.divider, t.headerRow]">
                    <th
                        v-for="col in columns"
                        :key="col.id"
                        class="px-3 py-2 text-left text-[11px] font-medium tracking-wider uppercase"
                        :style="col.width ? `width:${col.width}px` : undefined"
                    >
                        {{ col.label }}
                    </th>
                </tr>
            </thead>
            <tbody :class="['divide-y', t.rowBorder]">
                <tr
                    v-for="row in pagedRows"
                    :key="row.id"
                    class="transition-colors hover:bg-surface-hover"
                >
                    <td
                        v-for="col in columns"
                        :key="col.id"
                        :class="['px-3 py-2', t.text]"
                        :style="col.width ? `width:${col.width}px` : undefined"
                    >
                        <button
                            v-if="col.kind === 'action'"
                            type="button"
                            @click="runRowAction(col, row)"
                            :class="[
                                'inline-flex items-center gap-1 rounded-pill px-2.5 py-1 text-[11px] transition-colors',
                                variantClass[col.variant],
                            ]"
                            :title="col.label"
                        >
                            <RuntimeIcon
                                v-if="col.icon"
                                :name="col.icon"
                                :size="12"
                                aria-hidden
                            />
                            {{ col.label }}
                        </button>
                        <div
                            v-else-if="col.field.type === 'rich_text'"
                            class="prose prose-sm max-w-none [&_a]:text-accent-blue [&_a]:underline"
                            v-html="richTextCell(row.data[col.field.slug])"
                        />
                        <span
                            v-else-if="
                                col.field.type === 'color' &&
                                row.data[col.field.slug]
                            "
                            class="inline-flex items-center gap-1.5"
                        >
                            <span
                                class="size-3.5 shrink-0 rounded-full border border-black/10"
                                :style="{
                                    background: String(
                                        row.data[col.field.slug],
                                    ),
                                }"
                            />
                            {{ row.data[col.field.slug] }}
                        </span>
                        <!--
                            A description is usually the longest field an object
                            has; unbounded it eats the width the other columns
                            need, so it gets a ceiling and an ellipsis, with the
                            whole text one hover away.
                        -->
                        <span
                            v-else-if="col.field.type === 'long_text'"
                            class="block max-w-sm truncate"
                            :title="String(row.data[col.field.slug] ?? '')"
                        >
                            {{ row.data[col.field.slug] ?? '—' }}
                        </span>
                        <FieldValue
                            v-else
                            :field="col.field"
                            :value="row.data[col.field.slug]"
                            :context="contextFor(row)"
                            size="sm"
                        />
                    </td>
                </tr>
                <tr v-if="rows.length === 0">
                    <td
                        :colspan="columns.length"
                        :class="['px-3 py-6 text-center text-xs', t.textMuted]"
                    >
                        {{ block.empty_state_message ?? 'No records yet.' }}
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Pager (only when pagination is enabled and there's more than one page). -->
        <div
            v-if="pageCount > 1"
            :class="[
                'flex items-center justify-between gap-3 px-3 py-2 text-xs',
                t.textMuted,
            ]"
        >
            <span
                >{{ (page - 1) * pageSize + 1 }}–{{
                    Math.min(page * pageSize, rows.length)
                }}
                / {{ rows.length }}</span
            >
            <div class="flex items-center gap-1">
                <button
                    type="button"
                    class="rounded-md border border-medium px-2 py-1 transition-colors hover:bg-surface-hover disabled:opacity-40"
                    :disabled="page <= 1"
                    @click="goToPage(page - 1)"
                >
                    ‹
                </button>
                <span class="px-1">{{ page }} / {{ pageCount }}</span>
                <button
                    type="button"
                    class="rounded-md border border-medium px-2 py-1 transition-colors hover:bg-surface-hover disabled:opacity-40"
                    :disabled="page >= pageCount"
                    @click="goToPage(page + 1)"
                >
                    ›
                </button>
            </div>
        </div>
    </div>
</template>
