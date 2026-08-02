<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    Check,
    Columns3,
    Download,
    Search,
} from '@lucide/vue';
import DOMPurify from 'dompurify';
import { computed, inject, ref, watch } from 'vue';
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
import { formatFieldValue, type DisplayContext } from './fieldDisplay';

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
 * How this reader has arranged this table: which columns are folded away, what
 * order they sit in, and what it is sorted by.
 *
 * All three are the reader's, not the author's — the manifest says where to
 * start, and from there the arrangement belongs to whoever is looking at it. So
 * it is kept per table and survives the visit; re-hiding the same six columns
 * on every navigation is not a feature. The search term is deliberately absent:
 * a filter you did not type, still applied when you come back, hides rows you
 * would swear are missing.
 */
interface SortState {
    id: string;
    dir: 'asc' | 'desc';
}

interface TableView {
    hidden: string[];
    order: string[];
    sort: SortState | null;
}

const storageKey = computed(() => `sp:table-cols:${appSlug}:${props.block.id}`);

function loadView(): TableView {
    const fallback: TableView = {
        hidden: allColumns.value
            .filter((c) => c.kind === 'data' && c.hiddenByDefault)
            .map((c) => c.id),
        order: [],
        sort: null,
    };

    try {
        const raw = window.localStorage.getItem(storageKey.value);
        if (raw === null) return fallback;
        const saved = JSON.parse(raw);

        // The first version of this stored a bare array of hidden ids. Those
        // keys are already in readers' browsers, so they still have to mean
        // something.
        if (Array.isArray(saved)) {
            return { ...fallback, hidden: saved.filter(isString) };
        }
        if (saved !== null && typeof saved === 'object') {
            return {
                hidden: Array.isArray(saved.hidden)
                    ? saved.hidden.filter(isString)
                    : fallback.hidden,
                order: Array.isArray(saved.order)
                    ? saved.order.filter(isString)
                    : [],
                sort:
                    saved.sort !== null &&
                    typeof saved.sort?.id === 'string' &&
                    (saved.sort.dir === 'asc' || saved.sort.dir === 'desc')
                        ? { id: saved.sort.id, dir: saved.sort.dir }
                        : null,
            };
        }
    } catch {
        // A disabled or full localStorage is not a reason to fail to render.
    }
    return fallback;
}

function isString(v: unknown): v is string {
    return typeof v === 'string';
}

const view = ref<TableView>(loadView());

function persist(): void {
    try {
        window.localStorage.setItem(
            storageKey.value,
            JSON.stringify(view.value),
        );
    } catch {
        // Same as above: the arrangement just does not survive the visit.
    }
}

/**
 * Saved state is filtered against what the table actually has now. A manifest
 * that dropped or renamed a column must not leave a stale id behind, and a
 * column added since the reader last visited has to appear rather than fall
 * through a gap in the saved order.
 */
const knownIds = computed(() => new Set(allColumns.value.map((c) => c.id)));

const hidden = computed(
    () => new Set(view.value.hidden.filter((id) => knownIds.value.has(id))),
);

const orderedColumns = computed<Column[]>(() => {
    const saved = view.value.order.filter((id) => knownIds.value.has(id));
    if (saved.length === 0) return allColumns.value;

    const byId = new Map(allColumns.value.map((c) => [c.id, c]));
    const out: Column[] = [];
    for (const id of saved) {
        const col = byId.get(id);
        if (col) {
            out.push(col);
            byId.delete(id);
        }
    }
    // Anything the saved order never heard of keeps its manifest position
    // relative to what remains.
    for (const col of allColumns.value) {
        if (byId.has(col.id)) out.push(col);
    }
    return out;
});

const columns = computed<Column[]>(() =>
    orderedColumns.value.filter(
        (c) => c.kind === 'action' || !hidden.value.has(c.id),
    ),
);

/** Every data column, shown or not — the picker's menu, in reading order. */
const hideableColumns = computed<DataColumn[]>(
    () => orderedColumns.value.filter((c) => c.kind === 'data') as DataColumn[],
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
const WORDS: Record<string, Record<string, string>> = {
    columns: {
        en: 'Columns',
        es: 'Columnas',
        pt: 'Colunas',
        fr: 'Colonnes',
    },
    search: {
        en: 'Search…',
        es: 'Buscar…',
        pt: 'Pesquisar…',
        fr: 'Rechercher…',
    },
    noMatches: {
        en: 'No row matches',
        es: 'Ninguna fila coincide con',
        pt: 'Nenhuma linha corresponde a',
        fr: 'Aucune ligne ne correspond à',
    },
    // Said out loud, because the alternative is a search that answers "no such
    // record" about a record that exists further down the object.
    ofLoaded: {
        en: 'among the first {n} of {total}',
        es: 'entre los primeros {n} de {total}',
        pt: 'entre os primeiros {n} de {total}',
        fr: 'parmi les {n} premiers sur {total}',
    },
    showingOf: {
        en: 'Showing {n} of {total}',
        es: 'Mostrando {n} de {total}',
        pt: 'Mostrando {n} de {total}',
        fr: 'Affichage de {n} sur {total}',
    },
};

function word(
    key: string,
    replace: Record<string, string | number> = {},
): string {
    const lang = props.locale.slice(0, 2).toLowerCase();
    let out = WORDS[key][lang] ?? WORDS[key].en;
    for (const [token, value] of Object.entries(replace)) {
        out = out.replace(`{${token}}`, String(value));
    }

    return out;
}

/**
 * The rows on screen are a page of a bigger result.
 *
 * Everything this table does — sorting, searching, the pager — works on what it
 * was sent. That is fine when it was sent everything, and a lie when it was
 * not: a search for a record sitting past the limit answers "no row matches",
 * which reads as "it does not exist". The count is how the reader learns to
 * doubt the answer.
 */
const truncated = computed(() => props.data?.truncated === true);
const totalRows = computed(() => props.data?.total ?? rows.value.length);

/**
 * Who answers this table's questions.
 *
 * While the whole object fits under the block's ceiling, the browser does —
 * instantly, with no round trip, which is better. Past it the browser is
 * holding a page and cannot honestly sort or search the rest, so the question
 * goes to the database and the answer arrives through the URL, which makes a
 * sorted, searched, paged view a link someone can send.
 */
const serverMode = computed(
    () => props.data?.paged === true || truncated.value,
);

const paramKey = computed(() => 't' + props.block.id.slice(-6));

function urlParam(suffix: string): string {
    if (typeof window === 'undefined') return '';

    return (
        new URLSearchParams(window.location.search).get(
            paramKey.value + suffix,
        ) ?? ''
    );
}

/**
 * Ask the server for a different view of this table. Only `blockData` is
 * re-fetched, and the entry replaces rather than stacks — twenty keystrokes
 * should not be twenty presses of the back button.
 */
function pushView(changes: Record<string, string>): void {
    const params = new URLSearchParams(window.location.search);
    for (const [suffix, value] of Object.entries(changes)) {
        if (value === '') {
            params.delete(paramKey.value + suffix);
        } else {
            params.set(paramKey.value + suffix, value);
        }
    }
    const qs = params.toString();

    router.get(
        window.location.pathname + (qs !== '' ? '?' + qs : ''),
        {},
        {
            only: ['blockData'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

const pickerLabel = computed(() => {
    const shown = hideableColumns.value.length - hidden.value.size;

    // The count is the point when something is folded away: without it the
    // table looks complete and the reader never thinks to look.
    return hidden.value.size > 0
        ? `${word('columns')} ${shown}/${hideableColumns.value.length}`
        : word('columns');
});

/**
 * A search box earns its place once the list is long enough to be worth
 * searching. On four rows it is furniture, and the reader's eye is faster than
 * the round trip to the keyboard. The threshold reads the LOADED rows, so a
 * table gains the box as its object fills up.
 */
const showSearch = computed(() => {
    if ((props.block as { searchable?: boolean }).searchable === false) {
        return false;
    }
    // Never on the row count alone once the server is answering: a search that
    // narrows to two rows would take the box away mid-typing, and the object is
    // large by definition or we would not be here.
    return serverMode.value || rows.value.length > 8;
});

interface TableRow {
    id: string;
    data: Record<string, unknown>;
    labels?: Record<string, unknown>;
}

const rows = computed<TableRow[]>(() => props.data?.rows ?? []);

/**
 * Types that are quantities. They share an alignment and tabular figures, so a
 * column of money reads as a column: twelve digits line up under one, and the
 * eye compares magnitudes down the edge instead of hunting for the decimal.
 */
const NUMERIC_TYPES = [
    'number',
    'currency',
    'rating',
    'slider',
    'formula',
    'rollup',
    'lookup',
];

function isNumericColumn(col: Column): boolean {
    return col.kind === 'data' && NUMERIC_TYPES.includes(col.field.type);
}

/**
 * How a cell behaves when its content is longer than its share of the width.
 *
 * Nothing here depends on a text fitting. A long value truncates with an
 * ellipsis and keeps its full text in the title, and the table scrolls
 * sideways rather than wrapping every cell to three lines — which is what a
 * seventeen-column object used to do, turning a row into a paragraph.
 */
function cellClass(col: Column): string {
    if (col.kind === 'action') return 'sp-cell whitespace-nowrap';
    if (isNumericColumn(col)) {
        return 'sp-cell text-right tabular-nums whitespace-nowrap';
    }

    return 'sp-cell max-w-[32ch] truncate';
}

/** Which page of the result the reader is on. Reset by anything that changes
 * what the result contains — page 4 of a search that now returns two rows is
 * an empty screen the reader has to work out how to escape. */
const page = ref(
    typeof window === 'undefined'
        ? 1
        : Math.max(
              1,
              Number(
                  new URLSearchParams(window.location.search).get(
                      't' + props.block.id.slice(-6) + '_p',
                  ),
              ) || 1,
          ),
);

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

function toggleColumn(id: string): void {
    const next = new Set(hidden.value);
    if (next.has(id)) {
        next.delete(id);
    } else {
        // Never let the reader empty the table entirely.
        if (columns.value.filter((c) => c.kind === 'data').length <= 1) return;
        next.add(id);
    }
    view.value = { ...view.value, hidden: [...next] };
    persist();
}

/**
 * Sorting, over the rows the block loaded.
 *
 * A third click clears it rather than cycling back to ascending: "how the
 * author ordered this" is a real answer, and without a way back the reader
 * cannot return to it short of reloading the page.
 */
/**
 * What the reader last asked for, before the server has said anything back.
 *
 * The URL is where a sort LIVES — it is what makes the view shareable — but it
 * only changes once the visit lands, so reading it back is reading the past. A
 * second click on the same header saw no sort yet and asked for ascending
 * again, which is why a column would never turn around.
 */
const pendingSort = ref<SortState | null | undefined>(undefined);

const sort = computed<SortState | null>(() => {
    if (serverMode.value) {
        if (pendingSort.value !== undefined) return pendingSort.value;
        const [slug, dir] = urlParam('_s').split(':');
        const col = allColumns.value.find(
            (c): c is DataColumn => c.kind === 'data' && c.field.slug === slug,
        );

        return col === undefined
            ? null
            : { id: col.id, dir: dir === 'desc' ? 'desc' : 'asc' };
    }

    return view.value.sort !== null && knownIds.value.has(view.value.sort.id)
        ? view.value.sort
        : null;
});

function isSortable(col: Column): col is DataColumn {
    if (col.kind !== 'data') return false;
    const declared = (
        props.block.columns as Array<Record<string, unknown>>
    ).find((c) => c.id === col.id)?.sortable;

    return declared !== false;
}

function toggleSort(col: Column): void {
    if (!isSortable(col)) return;
    const current = sort.value;
    const next: SortState | null =
        current === null || current.id !== col.id
            ? { id: col.id, dir: 'asc' }
            : current.dir === 'asc'
              ? { id: col.id, dir: 'desc' }
              : null;

    if (serverMode.value) {
        pendingSort.value = next;
        // The slug, not the column id: it survives an edit that renumbers the
        // columns, and it is what a person reads in the URL.
        pushView({
            _s: next === null ? '' : `${col.field.slug}:${next.dir}`,
            _p: '',
        });

        return;
    }

    view.value = { ...view.value, sort: next };
    persist();
    page.value = 1;
}

/** Empty cells sort last whichever way the column is pointing. */
function isBlank(value: unknown): boolean {
    return (
        value === null ||
        value === undefined ||
        value === '' ||
        (Array.isArray(value) && value.length === 0)
    );
}

/**
 * What a cell is worth when ordering by it: the number for anything numeric,
 * the ISO string for a date (which sorts correctly as text), the resolved
 * record label for a relation — the id it stores would order by when the row
 * was written, which is not what the reader clicked the header to ask.
 */
function sortValue(row: TableRow, col: DataColumn): number | string {
    const raw = row.data[col.field.slug];

    switch (col.field.type) {
        case 'number':
        case 'currency':
        case 'rating':
        case 'slider':
        case 'formula':
        case 'rollup':
        case 'lookup': {
            const n = Number(raw);
            return Number.isFinite(n) ? n : String(raw).toLowerCase();
        }
        case 'boolean':
            return raw ? 1 : 0;
        case 'relation':
            return String(row.labels?.[col.field.slug] ?? raw).toLowerCase();
        default:
            return String(raw).toLowerCase();
    }
}

/**
 * Search, across every column on screen.
 *
 * Matched against what the cell SHOWS, not what it stores: a reader typing
 * "Rentado" is looking at the word in front of them, not at the option value
 * `rentado` underneath it, and typing a date the way the page prints it should
 * find that row. Accents are folded, so "direccion" finds "Dirección" —
 * otherwise the search fails exactly where Spanish needs it most.
 */
const query = ref(
    typeof window === 'undefined'
        ? ''
        : (new URLSearchParams(window.location.search).get(
              't' + props.block.id.slice(-6) + '_q',
          ) ?? ''),
);

function fold(value: string): string {
    return value
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();
}

const searchableRows = computed(() => {
    // The database already answered; filtering again would only remove rows it
    // deliberately kept (it searches every text field, not just the visible
    // columns).
    if (serverMode.value) return rows.value;

    const needle = fold(query.value.trim());
    if (needle === '') return rows.value;

    const dataColumns = columns.value.filter(
        (c): c is DataColumn => c.kind === 'data',
    );

    return rows.value.filter((row) =>
        dataColumns.some((col) =>
            fold(
                formatFieldValue(
                    col.field,
                    row.data[col.field.slug],
                    contextFor(row),
                ),
            ).includes(needle),
        ),
    );
});

const sortedRows = computed(() => {
    if (serverMode.value) return searchableRows.value;

    const state = sort.value;
    if (state === null) return searchableRows.value;

    const col = orderedColumns.value.find(
        (c): c is DataColumn => c.kind === 'data' && c.id === state.id,
    );
    if (col === undefined) return searchableRows.value;

    const dir = state.dir === 'desc' ? -1 : 1;

    return [...searchableRows.value].sort((a, b) => {
        const aBlank = isBlank(a.data[col.field.slug]);
        const bBlank = isBlank(b.data[col.field.slug]);
        if (aBlank !== bBlank) return aBlank ? 1 : -1;
        if (aBlank && bBlank) return 0;

        const av = sortValue(a, col);
        const bv = sortValue(b, col);

        if (typeof av === 'number' && typeof bv === 'number') {
            return (av - bv) * dir;
        }
        // localeCompare so "Ávila" lands beside "Avila" rather than after Z.
        return String(av).localeCompare(String(bv), props.locale) * dir;
    });
});

/**
 * Dragging a header to move its column. Kept to the pointer gesture people
 * already expect from a spreadsheet; the picker beside it is the keyboard- and
 * screen-reader-reachable way to change what is on screen.
 */
const draggingId = ref<string | null>(null);
const dragOverId = ref<string | null>(null);

function onDragStart(col: Column, event: DragEvent): void {
    draggingId.value = col.id;
    event.dataTransfer?.setData('text/plain', col.id);
    if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move';
}

function onDragOver(col: Column): void {
    if (draggingId.value !== null && draggingId.value !== col.id) {
        dragOverId.value = col.id;
    }
}

function onDrop(target: Column): void {
    const moved = draggingId.value;
    draggingId.value = null;
    dragOverId.value = null;
    if (moved === null || moved === target.id) return;

    const ids = orderedColumns.value.map((c) => c.id);
    const from = ids.indexOf(moved);
    const to = ids.indexOf(target.id);
    if (from < 0 || to < 0) return;

    ids.splice(to, 0, ...ids.splice(from, 1));
    view.value = { ...view.value, order: ids };
    persist();
}

function onDragEnd(): void {
    draggingId.value = null;
    dragOverId.value = null;
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

// Client-side pagination, now over the rows left after searching and in the
// order the reader asked for. (The data_source.limit caps how many rows are
// loaded in total; everything here works within those.)
const pageSize = computed(
    () =>
        (props.block as { pagination?: { page_size?: number } }).pagination
            ?.page_size ?? 0,
);
const pageCount = computed(() => {
    // In server mode the page IS the result, so the count comes from how many
    // records match rather than from how many arrived.
    if (serverMode.value) {
        return Math.max(
            1,
            Math.ceil(totalRows.value / Math.max(1, pageSize.value)),
        );
    }

    return pageSize.value > 0
        ? Math.max(1, Math.ceil(sortedRows.value.length / pageSize.value))
        : 1;
});
const pagedRows = computed(() => {
    if (serverMode.value || pageSize.value <= 0) return sortedRows.value;
    const start = (Math.min(page.value, pageCount.value) - 1) * pageSize.value;
    return sortedRows.value.slice(start, start + pageSize.value);
});
function goToPage(p: number) {
    const target = Math.min(Math.max(1, p), pageCount.value);
    if (serverMode.value) {
        pushView({ _p: target === 1 ? '' : String(target) });

        return;
    }
    page.value = target;
}

/**
 * Typing is not a request per keystroke. Debounced in server mode, immediate in
 * the browser where there is nothing to wait for.
 */
let searchTimer: ReturnType<typeof setTimeout> | undefined;
watch(query, (value) => {
    page.value = 1;
    if (!serverMode.value) return;

    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => pushView({ _q: value.trim(), _p: '' }), 300);
});

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
            v-if="exportHref || showPicker || showSearch || truncated"
            :class="['flex items-center gap-1 border-b px-3 py-1.5', t.divider]"
        >
            <label v-if="showSearch" class="relative mr-auto flex items-center">
                <Search
                    :class="[
                        'pointer-events-none absolute left-2 size-3',
                        t.textMuted,
                    ]"
                    aria-hidden
                />
                <input
                    v-model="query"
                    type="search"
                    :placeholder="word('search')"
                    :class="[
                        'w-40 rounded-pill border border-transparent bg-surface-hover py-1 pr-2 pl-7 text-[11px] transition-[width,border-color] outline-none focus:w-56 focus:border-accent-blue',
                        t.text,
                    ]"
                />
            </label>
            <span v-else class="mr-auto" />
            <!--
                Standing notice, not just on an empty search: the reader has to
                know the list is partial BEFORE concluding anything from it.
            -->
            <span
                v-if="truncated"
                :class="['mr-1 text-[11px]', t.textMuted]"
                :title="word('showingOf', { n: rows.length, total: totalRows })"
            >
                {{ word('showingOf', { n: rows.length, total: totalRows }) }}
            </span>
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
        <!--
            The table scrolls inside its own card rather than growing the page.
            Four hundred rows used to make the window taller than the screen
            and take the toolbar — search, columns, export — up and away with
            it. A short table is shorter than the ceiling and unaffected.
        -->
        <div class="max-h-[70vh] overflow-auto">
            <table class="w-full border-collapse text-sm">
                <thead class="sticky top-0 z-10">
                    <tr
                        :class="['border-b', t.divider, t.headerRow]"
                        :style="{ background: 'var(--sp-surface-2)' }"
                    >
                        <th
                            v-for="col in columns"
                            :key="col.id"
                            class="sp-cell text-[11px] font-medium tracking-wider whitespace-nowrap uppercase transition-colors"
                            :class="[
                                isNumericColumn(col)
                                    ? 'text-right'
                                    : 'text-left',
                                dragOverId === col.id
                                    ? 'bg-accent-blue/15'
                                    : draggingId === col.id
                                      ? 'opacity-40'
                                      : '',
                            ]"
                            :style="
                                col.width ? `width:${col.width}px` : undefined
                            "
                            :aria-sort="
                                sort?.id === col.id
                                    ? sort.dir === 'asc'
                                        ? 'ascending'
                                        : 'descending'
                                    : undefined
                            "
                            draggable="true"
                            @dragstart="onDragStart(col, $event)"
                            @dragover.prevent="onDragOver(col)"
                            @drop.prevent="onDrop(col)"
                            @dragend="onDragEnd"
                        >
                            <button
                                v-if="isSortable(col)"
                                type="button"
                                class="inline-flex items-center gap-1 uppercase transition-opacity hover:opacity-70"
                                :data-sp-sort="col.id"
                                @click="toggleSort(col)"
                            >
                                {{ col.label }}
                                <ArrowUp
                                    v-if="
                                        sort?.id === col.id &&
                                        sort.dir === 'asc'
                                    "
                                    class="size-3"
                                />
                                <ArrowDown
                                    v-else-if="sort?.id === col.id"
                                    class="size-3"
                                />
                            </button>
                            <template v-else>{{ col.label }}</template>
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
                            :class="[cellClass(col), t.text]"
                            :style="
                                col.width ? `width:${col.width}px` : undefined
                            "
                            :title="
                                col.kind === 'data'
                                    ? String(row.data[col.field.slug] ?? '')
                                    : undefined
                            "
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
                    <tr v-if="sortedRows.length === 0">
                        <td
                            :colspan="columns.length"
                            :class="[
                                'px-3 py-6 text-center text-xs',
                                t.textMuted,
                            ]"
                        >
                            <!--
                            An empty object and a search that found nothing are
                            different facts. Saying "No records yet" to someone
                            who just typed reads as "this object is empty",
                            which sends them looking for a bug in their data.
                        -->
                            <template v-if="rows.length > 0">
                                {{ word('noMatches') }} “{{ query }}”<template
                                    v-if="truncated"
                                >
                                    {{
                                        word('ofLoaded', {
                                            n: rows.length,
                                            total: totalRows,
                                        })
                                    }}</template
                                >.
                            </template>
                            <template v-else>
                                {{
                                    block.empty_state_message ??
                                    'No records yet.'
                                }}
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

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
                    serverMode
                        ? (page - 1) * pageSize + rows.length
                        : Math.min(page * pageSize, sortedRows.length)
                }}
                / {{ serverMode ? totalRows : sortedRows.length }}</span
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
