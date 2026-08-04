<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, inject, ref } from 'vue';
import type { FieldDef, ObjectDef } from '../types/manifest';
import { useActionExecutor, type RuntimeAction } from '../useActionExecutor';
import { useChartTooltip } from '../useChartTooltip';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';
import ChartTooltip from './ChartTooltip.vue';

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
    data:
        | { rows: RowData[]; can?: { update: boolean; delete: boolean } }
        | undefined;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

const t = themeTokens(useRuntimeTheme());
const appSlug = inject<string>('appSlug', '');
const { execute } = useActionExecutor();
const { card, mouse, tip, onMove, showTip, hideTip } = useChartTooltip();

const object = computed<ObjectDef | undefined>(() =>
    props.objects.find((o) => o.id === props.block.data_source.object_id),
);

function fieldOf(id: string | undefined): FieldDef | undefined {
    if (!id) return undefined;
    return object.value?.fields.find((f) => f.id === id);
}

/**
 * Dragging a bar along the timeline.
 *
 * Offered only where the reader could have changed the same two dates by
 * opening the record — the server says so with the rows. A control that is
 * always refused should not be drawn.
 */
const canMove = computed(() => props.data?.can?.update === true);

/** Whole days a bar has been dragged by, before the server has confirmed it. */
const shifted = ref<Record<string, number>>({});

const dragFrom = ref<{ id: string; x: number; pxPerDay: number } | null>(null);

function startDrag(event: PointerEvent, id: string): void {
    if (!canMove.value || model.value === null) return;

    const bar = event.currentTarget as HTMLElement;
    const trackWidth = bar.parentElement?.clientWidth ?? 0;
    if (trackWidth === 0) return;

    bar.setPointerCapture?.(event.pointerId);

    // The track spans the whole visible range, so one pixel is a known number
    // of days — without that, a drag is a gesture with no unit. Measured here
    // rather than in the template: a cast and a non-null assertion in a
    // template expression is TypeScript the template compiler does not owe us,
    // and when it refuses the whole block silently renders nothing.
    const days = Math.max(1, model.value.spanDays);
    dragFrom.value = { id, x: event.clientX, pxPerDay: trackWidth / days };
}

function moveDrag(event: PointerEvent): void {
    const from = dragFrom.value;
    if (from === null) return;
    event.preventDefault();

    const days = Math.round((event.clientX - from.x) / from.pxPerDay);
    shifted.value = { ...shifted.value, [from.id]: days };
}

async function endDrag(): Promise<void> {
    const from = dragFrom.value;
    dragFrom.value = null;
    if (from === null || !object.value) return;

    const days = shifted.value[from.id] ?? 0;
    if (days === 0) return;

    const row = (props.data?.rows ?? []).find((r) => r.id === from.id);
    const sStart = startField.value?.slug;
    const sEnd = endField.value?.slug;
    if (row === undefined || !sStart || !sEnd) return;

    const moved = (raw: unknown): string | null => {
        const at = parseDate(raw);
        return at === null
            ? null
            : new Date(at + days * 86_400_000).toISOString().slice(0, 10);
    };

    const start = moved(row.data[sStart]);
    const end = moved(row.data[sEnd]);
    if (start === null || end === null) return;

    const result = await execute(
        [
            {
                type: 'update_record',
                object_id: object.value.id,
                record_id_expression: from.id,
                values: { [sStart]: start, [sEnd]: end },
            } as RuntimeAction,
        ],
        { appSlug, params: {}, row: {} },
    );

    // Either way the local shift goes: on success the reloaded rows already
    // carry the new dates, and on failure the bar belongs where it was. A bar
    // left showing dates the server refused is a lie the reader will plan on.
    const next = { ...shifted.value };
    delete next[from.id];
    shifted.value = next;

    if (result.ok) {
        router.reload({ only: ['blockData'] });
    }
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
            // Days the reader has dragged this bar by but the server has not
            // confirmed yet. Applied to BOTH ends: a task keeps its length when
            // it moves, which is the only behaviour anybody expects.
            const shift = (shifted.value[r.id] ?? 0) * 86_400_000;
            const start = parseDate(r.data[sStart]);
            let end = parseDate(r.data[sEnd]);
            if (start === null || end === null) return null;
            if (end < start) end = start; // guard inverted ranges
            const title = sTitle ? String(r.data[sTitle] ?? r.id) : r.id;
            return {
                row: r,
                start: start + shift,
                end: end + shift,
                title,
            };
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

    return {
        bars,
        minLabel: fmtDate(min),
        maxLabel: fmtDate(max),
        // How many days the track spans, so a drag in pixels becomes a drag in
        // days rather than an unscaled gesture.
        spanDays: Math.max(1, Math.round(total / 86_400_000)),
    };
});
</script>

<template>
    <div
        ref="card"
        :class="['relative rounded-sp-sm border p-5', t.surface]"
        @mousemove="onMove"
        @mouseleave="hideTip"
    >
        <ChartTooltip :tip="tip" :x="mouse.x" :y="mouse.y" />
        <p
            v-if="block.label"
            :class="['mb-3 text-[11px] tracking-wider uppercase', t.textSubtle]"
        >
            {{ block.label }}
        </p>

        <p v-if="!model" :class="['py-6 text-center text-xs', t.textMuted]">
            No dated records to schedule.
        </p>

        <div v-else class="space-y-1.5">
            <div
                v-for="bar in model.bars"
                :key="bar.id"
                class="flex items-center gap-3"
            >
                <span
                    :class="['w-32 shrink-0 truncate text-xs', t.text]"
                    :title="bar.title"
                    >{{ bar.title }}</span
                >
                <div
                    class="relative h-5 flex-1 overflow-hidden rounded-xs bg-surface"
                >
                    <div
                        :data-sp-gantt-bar="bar.id"
                        :class="[
                            'absolute inset-y-0 rounded-xs transition-opacity hover:opacity-80',
                            canMove
                                ? 'cursor-grab touch-none'
                                : 'cursor-pointer',
                            dragFrom?.id === bar.id ? 'opacity-70' : '',
                        ]"
                        :style="{
                            left: bar.left + '%',
                            width: bar.width + '%',
                            background: bar.color,
                        }"
                        @mouseenter="showTip(bar.title, bar.range, bar.color)"
                        @pointerdown="startDrag($event, bar.id)"
                        @pointermove="moveDrag"
                        @pointerup="endDrag"
                        @pointercancel="endDrag"
                    />
                </div>
            </div>

            <div
                :class="[
                    'flex justify-between pt-1 pl-[8.75rem] text-[10px]',
                    t.textMuted,
                ]"
            >
                <span>{{ model.minLabel }}</span>
                <span>{{ model.maxLabel }}</span>
            </div>
        </div>
    </div>
</template>
