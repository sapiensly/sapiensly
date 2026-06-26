<script setup lang="ts">
import { computed } from 'vue';
import type { FieldDef, ObjectDef } from '../types/manifest';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';

interface GanttBlock {
    id: string;
    type: 'gantt';
    label?: string;
    data_source: { object_id: string };
    start_field_id: string;
    end_field_id: string;
    title_field_id: string;
    color_field_id?: string;
}

interface RowData {
    id: string;
    data: Record<string, unknown>;
}

const props = defineProps<{
    block: GanttBlock;
    data: { rows: RowData[] } | undefined;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

const t = themeTokens(useRuntimeTheme());

const object = computed<ObjectDef | undefined>(() =>
    props.objects.find((o) => o.id === props.block.data_source.object_id),
);

function fieldOf(id: string | undefined): FieldDef | undefined {
    if (!id) return undefined;
    return object.value?.fields.find((f) => f.id === id);
}

const startField = computed(() => fieldOf(props.block.start_field_id));
const endField = computed(() => fieldOf(props.block.end_field_id));
const titleField = computed(() => fieldOf(props.block.title_field_id));
const colorField = computed(() => fieldOf(props.block.color_field_id));

function parseDate(value: unknown): number | null {
    if (value === null || value === undefined || value === '') return null;
    const ms = new Date(String(value)).getTime();
    return Number.isFinite(ms) ? ms : null;
}

function colorFor(row: RowData): string {
    const cf = colorField.value;
    if (cf?.type === 'single_select') {
        const opt = cf.options?.find((o) => o.value === row.data[cf.slug]);
        if (opt?.color) return opt.color;
    }
    return 'var(--sp-accent-blue, #3B82F6)';
}

interface Bar {
    id: string;
    title: string;
    left: number;
    width: number;
    color: string;
    range: string;
}

const fmtDate = (ms: number) => new Date(ms).toLocaleDateString(props.locale);

const model = computed(() => {
    const rows = props.data?.rows ?? [];
    const sStart = startField.value?.slug;
    const sEnd = endField.value?.slug;
    const sTitle = titleField.value?.slug;
    if (!sStart || !sEnd) return null;

    const spans = rows
        .map((r) => {
            const start = parseDate(r.data[sStart]);
            let end = parseDate(r.data[sEnd]);
            if (start === null || end === null) return null;
            if (end < start) end = start; // guard inverted ranges
            const title = sTitle ? String(r.data[sTitle] ?? r.id) : r.id;
            return { row: r, start, end, title };
        })
        .filter((s): s is NonNullable<typeof s> => s !== null);

    if (spans.length === 0) return null;

    const min = Math.min(...spans.map((s) => s.start));
    const max = Math.max(...spans.map((s) => s.end));
    const total = Math.max(1, max - min);

    const bars: Bar[] = spans.map((s) => ({
        id: s.row.id,
        title: s.title,
        left: ((s.start - min) / total) * 100,
        width: Math.max(1.5, ((s.end - s.start) / total) * 100),
        color: colorFor(s.row),
        range: `${fmtDate(s.start)} → ${fmtDate(s.end)}`,
    }));

    return { bars, minLabel: fmtDate(min), maxLabel: fmtDate(max) };
});
</script>

<template>
    <div :class="['rounded-sp-sm border p-5', t.surface]">
        <p v-if="block.label" :class="['mb-3 text-[11px] uppercase tracking-wider', t.textSubtle]">{{ block.label }}</p>

        <p v-if="!model" :class="['py-6 text-center text-xs', t.textMuted]">No dated records to schedule.</p>

        <div v-else class="space-y-1.5">
            <div
                v-for="bar in model.bars"
                :key="bar.id"
                class="flex items-center gap-3"
            >
                <span :class="['w-32 shrink-0 truncate text-xs', t.text]" :title="bar.title">{{ bar.title }}</span>
                <div class="relative h-5 flex-1 overflow-hidden rounded-xs bg-surface">
                    <div
                        class="absolute inset-y-0 rounded-xs"
                        :style="{ left: bar.left + '%', width: bar.width + '%', background: bar.color }"
                        :title="bar.title + ' — ' + bar.range"
                    />
                </div>
            </div>

            <div :class="['flex justify-between pt-1 pl-[8.75rem] text-[10px]', t.textMuted]">
                <span>{{ model.minLabel }}</span>
                <span>{{ model.maxLabel }}</span>
            </div>
        </div>
    </div>
</template>
