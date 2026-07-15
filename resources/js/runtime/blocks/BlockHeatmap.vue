<script setup lang="ts">
import { computed } from 'vue';
import type { FieldDef, ObjectDef } from '../types/manifest';
import { resolveField } from '../types/manifest';
import { useChartTooltip } from '../useChartTooltip';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';
import ChartTooltip from './ChartTooltip.vue';

interface HeatmapBlock {
    id: string;
    type: 'heatmap';
    label?: string;
    data_source: { object_id: string };
    date_field_id: string;
    weeks?: number;
    color?: string;
}

interface RowData {
    id: string;
    data: Record<string, unknown>;
}

const props = defineProps<{
    block: HeatmapBlock;
    data: { rows: RowData[] } | undefined;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

const t = themeTokens(useRuntimeTheme());
const { card, mouse, tip, onMove, showTip, hideTip } = useChartTooltip();

const object = computed<ObjectDef | undefined>(() =>
    props.objects.find((o) => o.id === props.block.data_source.object_id),
);

const dateField = computed<FieldDef | undefined>(() =>
    resolveField(object.value, props.block.date_field_id),
);

function toIsoLocal(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

const weeks = computed(() => props.block.weeks ?? 26);
// Default to the palette's first series so the density ramp follows palette_mode
// (grays → a grey heatmap); an explicit block.color still wins.
const color = computed(() => props.block.color ?? 'var(--sp-chart-1, #3B82F6)');

// Count rows per ISO date.
const countsByDate = computed<Record<string, number>>(() => {
    const out: Record<string, number> = {};
    if (!dateField.value) return out;
    const slug = dateField.value.slug;
    for (const r of props.data?.rows ?? []) {
        const raw = r.data[slug];
        if (!raw) continue;
        const d = new Date(String(raw));
        if (isNaN(d.getTime())) continue;
        const iso = toIsoLocal(d);
        out[iso] = (out[iso] ?? 0) + 1;
    }
    return out;
});

interface Cell {
    iso: string;
    count: number;
    intensity: number; // 0-1
    isFuture: boolean;
}

const grid = computed<Cell[][]>(() => {
    // Last weeks complete, each column = one week, rows = Mon..Sun
    const todayLocal = new Date();
    todayLocal.setHours(0, 0, 0, 0);

    // End anchor: this week's Sunday
    const endAnchor = new Date(todayLocal);
    const dow = (endAnchor.getDay() + 6) % 7; // Mon=0..Sun=6
    endAnchor.setDate(endAnchor.getDate() + (6 - dow));

    const startAnchor = new Date(endAnchor);
    startAnchor.setDate(endAnchor.getDate() - (weeks.value * 7 - 1));

    const counts = countsByDate.value;
    const max = Math.max(1, ...Object.values(counts));

    const cols: Cell[][] = [];
    const cursor = new Date(startAnchor);
    for (let w = 0; w < weeks.value; w++) {
        const week: Cell[] = [];
        for (let d = 0; d < 7; d++) {
            const iso = toIsoLocal(cursor);
            const count = counts[iso] ?? 0;
            week.push({
                iso,
                count,
                intensity: count === 0 ? 0 : 0.15 + 0.85 * (count / max),
                isFuture: cursor > todayLocal,
            });
            cursor.setDate(cursor.getDate() + 1);
        }
        cols.push(week);
    }
    return cols;
});

function cellStyle(cell: Cell): string {
    if (cell.isFuture) return 'background: rgba(255,255,255,0.02)';
    if (cell.count === 0) return 'background: rgba(255,255,255,0.05)';
    return `background: color-mix(in oklab, ${color.value} ${Math.round(cell.intensity * 100)}%, transparent)`;
}
</script>

<template>
    <div
        ref="card"
        :class="['relative rounded-sp-sm border p-4', t.surface]"
        @mousemove="onMove"
        @mouseleave="hideTip"
    >
        <ChartTooltip :tip="tip" :x="mouse.x" :y="mouse.y" />
        <header v-if="block.label" class="mb-3">
            <p :class="['text-[11px] tracking-wider uppercase', t.textSubtle]">
                {{ block.label }}
            </p>
        </header>
        <div class="flex gap-[3px] overflow-x-auto">
            <div
                v-for="(week, wi) in grid"
                :key="wi"
                class="flex flex-col gap-[3px]"
            >
                <div
                    v-for="(cell, di) in week"
                    :key="di"
                    class="size-[11px] cursor-pointer rounded-[2px] transition-transform hover:scale-125"
                    :style="cellStyle(cell)"
                    @mouseenter="
                        showTip(
                            cell.iso,
                            cell.count
                                ? cell.count +
                                      (cell.count === 1
                                          ? ' registro'
                                          : ' registros')
                                : 'Sin registros',
                            color,
                        )
                    "
                />
            </div>
        </div>
    </div>
</template>
