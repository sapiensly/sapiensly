<script setup lang="ts">
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
    const m = window.location.pathname.match(/^\/r\/([a-z][a-z0-9_]*)/);
    return m?.[1] ?? '';
}

const object = computed<ObjectDef | undefined>(() =>
    props.objects.find((o) => o.id === props.block.data_source.object_id),
);

interface DataColumn {
    kind: 'data';
    id: string;
    label: string;
    field: FieldDef;
    width?: number;
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

const columns = computed<Column[]>(() =>
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
            };
        })
        .filter((c): c is Column => c !== null),
);

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

function formatCell(field: FieldDef, value: unknown): string {
    if (value === null || value === undefined) return '—';
    if (field.type === 'currency' && typeof value === 'number') {
        return new Intl.NumberFormat(props.locale, {
            style: 'currency',
            currency: field.currency_code ?? props.defaultCurrency ?? 'MXN',
        }).format(value);
    }
    if (field.type === 'number' && typeof value === 'number') {
        return new Intl.NumberFormat(props.locale).format(value);
    }
    if (field.type === 'boolean') return value ? '✓' : '—';
    if (field.type === 'single_select') {
        const option = field.options?.find((o) => o.value === value);
        return option?.label ?? String(value);
    }
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
    if (field.type === 'rating') {
        const n = Number(value);
        const max = (field as unknown as { max?: number }).max ?? 5;
        const icon =
            (field as unknown as { icon?: string }).icon === 'heart'
                ? '♥'
                : (field as unknown as { icon?: string }).icon === 'thumb'
                  ? '👍'
                  : '★';
        return (
            icon.repeat(Math.max(0, Math.min(max, Math.round(n)))) +
            ` ${n}/${max}`
        );
    }
    if (field.type === 'slider') {
        const n = Number(value);
        const fmt = (field as unknown as { format?: string }).format ?? 'plain';
        if (fmt === 'percentage') return `${n}%`;
        if (fmt === 'currency') {
            try {
                return new Intl.NumberFormat(props.locale, {
                    style: 'currency',
                    currency:
                        (field as unknown as { currency_code?: string })
                            .currency_code ??
                        props.defaultCurrency ??
                        'MXN',
                }).format(n);
            } catch {
                return String(n);
            }
        }
        return new Intl.NumberFormat(props.locale).format(n);
    }
    if (field.type === 'date_range' && value && typeof value === 'object') {
        const r = value as { from?: string; to?: string };
        const fmt = (s?: string) => {
            if (!s) return '—';
            try {
                return new Date(s).toLocaleDateString(props.locale);
            } catch {
                return s;
            }
        };
        return `${fmt(r.from)} → ${fmt(r.to)}`;
    }
    if (field.type === 'file' && value && typeof value === 'object') {
        const f = value as { original_name?: string; size_bytes?: number };
        const size = f.size_bytes ?? 0;
        const sizeStr =
            size < 1024
                ? `${size} B`
                : size < 1024 * 1024
                  ? `${(size / 1024).toFixed(0)} KB`
                  : `${(size / 1024 / 1024).toFixed(1)} MB`;
        return `${f.original_name ?? '(unnamed)'} · ${sizeStr}`;
    }
    return String(value);
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
                <tr v-for="row in pagedRows" :key="row.id">
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
                        <template v-else>{{
                            formatCell(col.field, row.data[col.field.slug])
                        }}</template>
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
