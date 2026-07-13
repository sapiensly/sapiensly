<script setup lang="ts">
/**
 * A 2-D matrix — the breakdown the query layer has always been able to compute
 * and nothing could draw.
 *
 * In `matrix` mode each cell is the aggregate itself (revenue by region AND
 * month). In `cohort` mode the columns stop being calendar dates and become the
 * OFFSET from each row's own start — month 0, month 1, month 2 — and each cell
 * becomes the share of that cohort still present. That shift is the whole
 * difference between a table of numbers and a retention curve: cohorts that
 * began in different months become comparable, because they are read from their
 * own beginning rather than from the calendar's.
 */
import { computed } from 'vue';
import type { FieldDef, ObjectDef } from '../types/manifest';
import { resolveField } from '../types/manifest';
import { useChartTooltip } from '../useChartTooltip';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';
import ChartTooltip from './ChartTooltip.vue';

type Bucket = 'day' | 'week' | 'month' | 'quarter' | 'year';

interface PivotBlock {
    id: string;
    type: 'pivot';
    label?: string;
    description?: string;
    data_source: { object_id: string };
    group_by_field_id: string;
    bucket?: Bucket;
    column_field_id: string;
    column_bucket?: Bucket;
    y_field_id?: string;
    aggregation: string;
    mode?: 'matrix' | 'cohort';
    format?: 'number' | 'currency' | 'percentage';
}

const props = defineProps<{
    block: PivotBlock;
    data:
        | {
              groups?: { group: unknown; group2?: unknown; value: number }[];
              error?: string;
          }
        | undefined;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

const t = themeTokens(useRuntimeTheme());
const { card, mouse, tip, onMove, showTip, hideTip } = useChartTooltip();

const object = computed<ObjectDef | undefined>(() =>
    props.objects.find((o) => o.id === props.block.data_source.object_id),
);
const rowField = computed<FieldDef | undefined>(() =>
    resolveField(object.value, props.block.group_by_field_id),
);
const colField = computed<FieldDef | undefined>(() =>
    resolveField(object.value, props.block.column_field_id),
);

const isCohort = computed(() => props.block.mode === 'cohort');

function isTemporal(field: FieldDef | undefined): boolean {
    return field?.type === 'date' || field?.type === 'datetime';
}

/**
 * A bucket key as a comparable period NUMBER, so two periods can be subtracted.
 *
 * The two stores speak different dialects: the record store buckets in SQL and
 * hands back a truncated timestamp, while a connected source is folded in memory
 * and hands back an already-formatted key ("2026-05", "2026-W18"). A cohort
 * offset is a subtraction, so both have to land on the same axis.
 */
function periodIndex(raw: unknown, bucket: Bucket): number | null {
    const s = String(raw ?? '').trim();
    if (s === '') return null;

    const quarter = s.match(/^(\d{4})-Q([1-4])$/);
    if (quarter) return Number(quarter[1]) * 4 + (Number(quarter[2]) - 1);

    const week = s.match(/^(\d{4})-W(\d{1,2})$/);
    if (week) return isoWeekIndex(Number(week[1]), Number(week[2]));

    const month = s.match(/^(\d{4})-(\d{2})$/);
    if (month) return Number(month[1]) * 12 + (Number(month[2]) - 1);

    if (/^\d{4}$/.test(s)) return Number(s);

    const d = new Date(s);
    if (Number.isNaN(d.getTime())) return null;
    const y = d.getUTCFullYear();
    const m = d.getUTCMonth();
    const dayMs = Date.UTC(y, m, d.getUTCDate());
    switch (bucket) {
        case 'year':
            return y;
        case 'quarter':
            return y * 4 + Math.floor(m / 3);
        case 'month':
            return y * 12 + m;
        case 'week':
            return Math.floor(dayMs / 604800000);
        default:
            return Math.floor(dayMs / 86400000);
    }
}

/** The Monday of an ISO week, on the same axis as the date branch above. */
function isoWeekIndex(year: number, week: number): number {
    const jan4 = Date.UTC(year, 0, 4);
    const dow = (new Date(jan4).getUTCDay() + 6) % 7; // Monday = 0
    const week1Monday = jan4 - dow * 86400000;
    return Math.floor((week1Monday + (week - 1) * 604800000) / 604800000);
}

/** A key as the user reads it — a date says its period, anything else says itself. */
function label(
    raw: unknown,
    field: FieldDef | undefined,
    bucket?: Bucket,
): string {
    const s = String(raw ?? '');
    if (s === '') return '—';
    if (!isTemporal(field)) return s;
    // An in-memory key is already formatted; a SQL timestamp is not.
    if (/^\d{4}(-(\d{2}|Q[1-4]|W\d{1,2}))?$/.test(s)) return s;

    const d = new Date(s);
    if (Number.isNaN(d.getTime())) return s;
    const y = d.getUTCFullYear();
    const m = String(d.getUTCMonth() + 1).padStart(2, '0');
    switch (bucket) {
        case 'year':
            return `${y}`;
        case 'quarter':
            return `${y}-Q${Math.floor(d.getUTCMonth() / 3) + 1}`;
        case 'month':
            return `${y}-${m}`;
        default:
            return `${y}-${m}-${String(d.getUTCDate()).padStart(2, '0')}`;
    }
}

const OFFSET_UNIT: Record<Bucket, string> = {
    day: 'Día',
    week: 'Sem.',
    month: 'Mes',
    quarter: 'Trim.',
    year: 'Año',
};

interface Cell {
    value: number | null;
    display: string;
    intensity: number; // 0..1, for the heat
}

/**
 * A cell's key. The separator matters: without one, ("a", "bc") and ("ab", "c")
 * are the same cell, and two unrelated rows quietly merge.
 */
function cellKey(row: unknown, col: unknown): string {
    return `${String(row ?? '')}\u001f${String(col ?? '')}`;
}

const matrix = computed(() => {
    const groups = props.data?.groups ?? [];
    if (groups.length === 0) return null;

    const rowBucket = props.block.bucket ?? 'month';
    const colBucket = props.block.column_bucket ?? 'month';

    // Rows in reading order: a date reads chronologically, a category by name.
    const rowKeys = [...new Set(groups.map((g) => String(g.group ?? '')))];
    rowKeys.sort((a, b) => {
        if (isTemporal(rowField.value)) {
            const ia = periodIndex(a, rowBucket) ?? 0;
            const ib = periodIndex(b, rowBucket) ?? 0;
            return ia - ib;
        }
        return a < b ? -1 : a > b ? 1 : 0;
    });

    // Raw value per (row, column).
    const cellOf = new Map<string, number>();
    for (const g of groups) {
        cellOf.set(cellKey(g.group, g.group2), Number(g.value) || 0);
    }

    if (!isCohort.value) {
        const colKeys = [...new Set(groups.map((g) => String(g.group2 ?? '')))];
        colKeys.sort((a, b) => {
            if (isTemporal(colField.value)) {
                const ia = periodIndex(a, colBucket) ?? 0;
                const ib = periodIndex(b, colBucket) ?? 0;
                return ia - ib;
            }
            return a < b ? -1 : a > b ? 1 : 0;
        });

        const max = Math.max(1, ...groups.map((g) => Number(g.value) || 0));
        const rows = rowKeys.map((rk) => ({
            label: label(rk, rowField.value, rowBucket),
            total: null as number | null,
            cells: colKeys.map((ck): Cell => {
                const v = cellOf.get(cellKey(rk, ck)) ?? null;
                return {
                    value: v,
                    display: v === null ? '' : formatValue(v),
                    intensity: v === null ? 0 : Math.min(1, v / max),
                };
            }),
        }));

        return {
            columns: colKeys.map((ck) => label(ck, colField.value, colBucket)),
            rows,
            cohort: false,
        };
    }

    // COHORT: the columns become the offset from each row's own beginning, so a
    // cohort that started in March is read from ITS month 0, not from January's.
    const offsets = new Set<number>();
    const byRowOffset = new Map<string, Map<number, number>>();

    for (const rk of rowKeys) {
        const base = periodIndex(rk, colBucket);
        if (base === null) continue;
        const perOffset = new Map<number, number>();
        for (const g of groups) {
            if (String(g.group ?? '') !== rk) continue;
            const at = periodIndex(g.group2, colBucket);
            if (at === null) continue;
            const offset = at - base;
            // Activity BEFORE the cohort was born is not retention, it's noise.
            if (offset < 0) continue;
            perOffset.set(
                offset,
                (perOffset.get(offset) ?? 0) + (Number(g.value) || 0),
            );
            offsets.add(offset);
        }
        byRowOffset.set(rk, perOffset);
    }

    const columns = [...offsets].sort((a, b) => a - b);
    const unit = OFFSET_UNIT[colBucket] ?? 'Periodo';

    const rows = rowKeys.map((rk) => {
        const perOffset = byRowOffset.get(rk) ?? new Map<number, number>();
        // The cohort's size is what it was at its own beginning. Without a
        // month 0 there is nothing to be a percentage OF, so the row stays blank
        // rather than inventing a denominator.
        const base = perOffset.get(columns[0] ?? 0) ?? perOffset.get(0) ?? 0;

        return {
            label: label(rk, rowField.value, rowBucket),
            total: base > 0 ? base : null,
            cells: columns.map((offset): Cell => {
                const v = perOffset.get(offset);
                if (v === undefined || base <= 0) {
                    return { value: null, display: '', intensity: 0 };
                }
                const pct = (v / base) * 100;
                return {
                    value: pct,
                    display: `${pct >= 99.5 ? 100 : Math.round(pct)}%`,
                    intensity: Math.min(1, pct / 100),
                };
            }),
        };
    });

    return {
        columns: columns.map((o) => `${unit} ${o}`),
        rows,
        cohort: true,
    };
});

function formatValue(v: number): string {
    const format = props.block.format ?? 'number';
    if (format === 'currency') {
        return new Intl.NumberFormat(props.locale, {
            style: 'currency',
            currency: props.defaultCurrency,
            maximumFractionDigits: 0,
        }).format(v);
    }
    if (format === 'percentage') {
        return `${Math.round(v * 100)}%`;
    }
    return new Intl.NumberFormat(props.locale, {
        maximumFractionDigits: 2,
    }).format(v);
}

/** The heat: one accent, deepening with the value. */
function cellStyle(cell: Cell): Record<string, string> {
    if (cell.value === null) return {};
    // Keep the faintest cells visible — a 2% cohort is still a fact.
    const alpha = 0.08 + cell.intensity * 0.72;
    return {
        background: `color-mix(in srgb, var(--sp-chart-1, #3b82f6) ${Math.round(alpha * 100)}%, transparent)`,
        color: cell.intensity > 0.55 ? 'var(--sp-on-accent, #fff)' : 'inherit',
    };
}
</script>

<template>
    <div ref="card" class="relative" @mousemove="onMove">
        <h3
            v-if="block.label"
            class="text-sm font-semibold"
            :style="{ color: t.text }"
        >
            {{ block.label }}
        </h3>
        <p
            v-if="block.description"
            class="mt-0.5 text-xs"
            :style="{ color: t.textSubtle }"
        >
            {{ block.description }}
        </p>

        <p
            v-if="data?.error"
            class="mt-3 text-xs"
            :style="{ color: t.textSubtle }"
        >
            {{ data.error }}
        </p>

        <p
            v-else-if="!matrix"
            class="mt-3 text-xs"
            :style="{ color: t.textSubtle }"
        >
            Sin datos suficientes para la matriz.
        </p>

        <div v-else class="mt-3 overflow-x-auto">
            <table class="w-full border-separate border-spacing-0.5 text-xs">
                <thead>
                    <tr>
                        <th
                            class="sticky left-0 px-2 py-1 text-left font-medium"
                            :style="{ color: t.textSubtle }"
                        ></th>
                        <th
                            v-if="matrix.cohort"
                            class="px-2 py-1 text-right font-medium"
                            :style="{ color: t.textSubtle }"
                        >
                            Cohorte
                        </th>
                        <th
                            v-for="col in matrix.columns"
                            :key="col"
                            class="px-2 py-1 text-center font-medium whitespace-nowrap"
                            :style="{ color: t.textSubtle }"
                        >
                            {{ col }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in matrix.rows" :key="row.label">
                        <th
                            class="sticky left-0 px-2 py-1 text-left font-medium whitespace-nowrap"
                            :style="{ color: t.text }"
                        >
                            {{ row.label }}
                        </th>
                        <td
                            v-if="matrix.cohort"
                            class="px-2 py-1 text-right tabular-nums"
                            :style="{ color: t.textSubtle }"
                        >
                            {{ row.total ?? '—' }}
                        </td>
                        <td
                            v-for="(cell, i) in row.cells"
                            :key="i"
                            class="rounded px-2 py-1 text-center tabular-nums"
                            :style="cellStyle(cell)"
                            @mouseenter="
                                cell.value !== null &&
                                showTip(
                                    `${row.label} · ${matrix.columns[i]}`,
                                    cell.display,
                                )
                            "
                            @mouseleave="hideTip()"
                        >
                            {{ cell.display || '·' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ChartTooltip :tip="tip" :x="mouse.x" :y="mouse.y" />
    </div>
</template>
