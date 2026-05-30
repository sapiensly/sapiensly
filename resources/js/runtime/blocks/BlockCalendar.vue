<script setup lang="ts">
import { computed, ref } from 'vue';
import type { FieldDef, ObjectDef } from '../types/manifest';
import { resolveField } from '../types/manifest';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';

interface CalendarBlock {
    id: string;
    type: 'calendar';
    data_source: { object_id: string };
    date_field_id: string;
    title_field_id: string;
    color_field_id?: string;
}

interface RowData {
    id: string;
    data: Record<string, unknown>;
}

const props = defineProps<{
    block: CalendarBlock;
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
    return resolveField(object.value, id);
}

const dateField = computed(() => fieldOf(props.block.date_field_id));
const titleField = computed(() => fieldOf(props.block.title_field_id));
const colorField = computed(() => fieldOf(props.block.color_field_id));

// Anchor — the first of the currently-displayed month.
const anchor = ref<Date>(startOfMonth(new Date()));

function startOfMonth(d: Date): Date {
    return new Date(d.getFullYear(), d.getMonth(), 1);
}

function shiftMonth(delta: number): void {
    const next = new Date(anchor.value);
    next.setMonth(next.getMonth() + delta);
    anchor.value = startOfMonth(next);
}

const monthLabel = computed(() =>
    anchor.value.toLocaleDateString(props.locale, { month: 'long', year: 'numeric' }),
);

interface DayCell {
    date: Date;
    iso: string; // YYYY-MM-DD in local time
    isCurrentMonth: boolean;
    isToday: boolean;
}

function toIsoLocal(d: Date): string {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

// 6×7 grid starting on Monday — covers any month edge cleanly.
const days = computed<DayCell[]>(() => {
    const first = anchor.value;
    const firstWeekday = (first.getDay() + 6) % 7; // 0=Mon
    const gridStart = new Date(first);
    gridStart.setDate(first.getDate() - firstWeekday);
    const todayIso = toIsoLocal(new Date());

    const out: DayCell[] = [];
    for (let i = 0; i < 42; i++) {
        const d = new Date(gridStart);
        d.setDate(gridStart.getDate() + i);
        out.push({
            date: d,
            iso: toIsoLocal(d),
            isCurrentMonth: d.getMonth() === first.getMonth(),
            isToday: toIsoLocal(d) === todayIso,
        });
    }
    return out;
});

interface CalendarEvent {
    id: string;
    title: string;
    color: string | null;
    iso: string;
}

const eventsByDay = computed<Record<string, CalendarEvent[]>>(() => {
    const out: Record<string, CalendarEvent[]> = {};
    if (!dateField.value) return out;
    const dateSlug = dateField.value.slug;
    const titleSlug = titleField.value?.slug;
    const colorSlug = colorField.value?.slug;

    for (const r of props.data?.rows ?? []) {
        const raw = r.data[dateSlug];
        if (!raw) continue;
        const d = new Date(String(raw));
        if (isNaN(d.getTime())) continue;
        const iso = toIsoLocal(d);
        const title = titleSlug ? String(r.data[titleSlug] ?? r.id) : r.id;

        let color: string | null = null;
        if (colorField.value?.type === 'single_select' && colorSlug) {
            const opt = colorField.value.options?.find((o) => o.value === r.data[colorSlug]);
            color = opt?.color ?? null;
        }

        if (!out[iso]) out[iso] = [];
        out[iso].push({ id: r.id, title, color, iso });
    }
    return out;
});

const weekdayHeaders = computed(() => {
    // Use a known Monday (2024-01-01 was a Monday) to render localised short names.
    const monday = new Date(2024, 0, 1);
    return Array.from({ length: 7 }, (_, i) => {
        const d = new Date(monday);
        d.setDate(monday.getDate() + i);
        return d.toLocaleDateString(props.locale, { weekday: 'short' });
    });
});
</script>

<template>
    <div :class="['rounded-sp-sm border', t.surface]">
        <header class="flex items-center justify-between border-b border-soft px-4 py-2">
            <h3 :class="['text-sm font-medium capitalize', t.text]">{{ monthLabel }}</h3>
            <div class="flex gap-1">
                <button
                    type="button"
                    @click="shiftMonth(-1)"
                    :class="['inline-flex size-7 items-center justify-center rounded-xs border border-medium bg-white/5 transition-colors hover:border-strong', t.textMuted]"
                >
                    <ChevronLeft class="size-3.5" />
                </button>
                <button
                    type="button"
                    @click="anchor = startOfMonth(new Date())"
                    :class="['inline-flex items-center rounded-xs border border-medium bg-white/5 px-2 text-[11px] transition-colors hover:border-strong', t.textMuted]"
                >
                    Today
                </button>
                <button
                    type="button"
                    @click="shiftMonth(1)"
                    :class="['inline-flex size-7 items-center justify-center rounded-xs border border-medium bg-white/5 transition-colors hover:border-strong', t.textMuted]"
                >
                    <ChevronRight class="size-3.5" />
                </button>
            </div>
        </header>

        <div class="grid grid-cols-7 border-b border-soft">
            <div
                v-for="(wd, i) in weekdayHeaders"
                :key="i"
                :class="['px-2 py-1.5 text-center text-[10px] uppercase tracking-wider', t.textSubtle]"
            >
                {{ wd }}
            </div>
        </div>

        <div class="grid grid-cols-7">
            <div
                v-for="day in days"
                :key="day.iso"
                :class="[
                    'min-h-[96px] border-b border-r border-soft p-1.5 text-[11px]',
                    day.isCurrentMonth ? '' : 'opacity-40',
                ]"
            >
                <p
                    :class="[
                        'mb-1 flex items-center justify-end text-[11px]',
                        day.isToday ? 'font-semibold text-accent-blue' : t.textMuted,
                    ]"
                >
                    {{ day.date.getDate() }}
                </p>
                <ul class="space-y-0.5">
                    <li
                        v-for="ev in (eventsByDay[day.iso] ?? []).slice(0, 3)"
                        :key="ev.id"
                        :class="['truncate rounded-xs px-1.5 py-0.5 text-[10px]', t.text]"
                        :style="ev.color ? `background: color-mix(in oklab, ${ev.color} 25%, transparent); border-left: 2px solid ${ev.color}` : 'background: rgba(59,130,246,0.15); border-left: 2px solid #3B82F6'"
                    >
                        {{ ev.title }}
                    </li>
                    <li
                        v-if="(eventsByDay[day.iso]?.length ?? 0) > 3"
                        :class="['text-[10px]', t.textSubtle]"
                    >
                        +{{ (eventsByDay[day.iso]!.length) - 3 }} more
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
