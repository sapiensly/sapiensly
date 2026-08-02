<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { runtimeWord } from '../words';
import { computed, inject, ref } from 'vue';
import RuntimeIcon from '../RuntimeIcon.vue';
import type { ObjectDef } from '../types/manifest';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';

interface Control {
    param: string;
    type: 'search' | 'select' | 'date_range';
    label?: string;
    placeholder?: string;
    options?: { value: string; label: string }[];
    default?: string;
}

// Preset windows for a date_range control. Each value maps server-side (via the
// range_start() expression function) to a window-start date. 'Año' is the widest
// preset: an unbounded 'Todo' is a trap on connected sources, whose fetch window
// is fixed at build time — clearing the in-memory filter can't widen the source,
// so 'Todo' would silently show the same rows as 'Año' (or fewer). See the reader
// range push-down that makes 'Año' actually re-fetch a year of connected data.
const DATE_RANGE_KEYS = ['today', '7d', '30d', '90d', '1y'] as const;

interface FilterBarBlock {
    id: string;
    type: 'filter_bar';
    label?: string;
    controls: Control[];
}

const props = defineProps<{
    block: FilterBarBlock;
    data: unknown;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

const t = themeTokens(useRuntimeTheme());

// Server meta: the ACTUAL span of data shown under the active window (count +
// min/max of the governing date field) — surfaces a capped or clustered
// external source instead of letting it read as a broken filter.
interface DateRangeMeta {
    param: string;
    count: number;
    min: string | null;
    max: string | null;
}
const rangeMeta = computed<DateRangeMeta | null>(() => {
    const meta = (props.data as { date_range?: DateRangeMeta } | null)
        ?.date_range;
    return meta && typeof meta.count === 'number' ? meta : null;
});

/** A phrase in the APP's language — these are the bar's own words. */
function word(key: string): string {
    return runtimeWord(props.locale, key);
}

const dateFmt = new Intl.DateTimeFormat(props.locale || 'es-MX', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    timeZone: 'UTC',
});
const fmt = (iso: string) => dateFmt.format(new Date(`${iso}T00:00:00Z`));

// The requested window for the active preset, mirroring the server's
// range_start() (UTC day arithmetic).
function windowLabel(param: string): string | null {
    const preset = values.value[param];
    if (!preset || preset === 'all') {
        return preset === 'all' ? word('range_all_label') : null;
    }
    const days: Record<string, number> = {
        today: 0,
        '7d': 7,
        '30d': 30,
        '90d': 90,
        '1y': 365,
    };
    if (!(preset in days)) {
        return null;
    }
    const now = new Date();
    const end = fmt(now.toISOString().slice(0, 10));
    if (preset === 'today') {
        return `hoy (${end})`;
    }
    const start = new Date(now.getTime() - days[preset] * 86_400_000);
    return `${fmt(start.toISOString().slice(0, 10))} – ${end}`;
}

// One caption per date_range control. When the server reports the ACTUAL data
// span (meta), lead with that real span — "20 abr 2026 – 29 jun 2026 · 11
// registros" — since it's what the viewer is actually looking at (a capped or
// clustered source rarely fills the requested window). Only when there's no meta
// (e.g. a not-yet-loaded or record-less block) do we fall back to the requested
// window so the bar never reads blank.
function rangeCaption(c: Control): string | null {
    if (c.type !== 'date_range') {
        return null;
    }
    const meta = rangeMeta.value;
    if (meta && meta.param === c.param) {
        if (meta.count === 0) {
            return 'sin registros en la ventana';
        }
        const span =
            meta.min && meta.max
                ? `${fmt(meta.min)} – ${fmt(meta.max)} · `
                : '';
        const noun = meta.count === 1 ? 'registro' : 'registros';
        return `${span}${meta.count} ${noun}`;
    }
    return windowLabel(c.param);
}

// The single caption shown on the right of the bar: the first date_range
// control's, with a clock glyph — mirrors the executive filter-bar mockup.
const primaryRangeCaption = computed<string | null>(() => {
    for (const c of props.block.controls) {
        const caption = rangeCaption(c);
        if (caption) {
            return caption;
        }
    }
    return null;
});

// Server-provided current filter values → SSR-safe initial state.
const pageParams = inject<Record<string, unknown>>('pageParams', {});
const values = ref<Record<string, string>>(
    Object.fromEntries(
        props.block.controls.map((c) => [
            c.param,
            String(pageParams[c.param] ?? c.default ?? ''),
        ]),
    ),
);

function presetsFor(c: Control): { value: string; label: string }[] {
    if (c.options && c.options.length) return c.options;

    const presets = DATE_RANGE_KEYS.map((value) => ({
        value,
        label: word(`range_${value}`),
    }));

    // 'Todo' is offered only when the author asked for it by defaulting to it.
    //
    // On a CONNECTED source an unbounded window is a trap: the fetch window is
    // fixed at build time, so clearing the in-memory filter cannot widen the
    // source and 'Todo' would silently show the same rows as 'Año' or fewer.
    // On a records-backed list it is the opposite — the page opens unfiltered,
    // and a bar that could narrow to 30 days with no way back is a dead end
    // somebody has to reload the page to escape.
    return c.default === 'all'
        ? [{ value: 'all', label: word('range_all') }, ...presets]
        : presets;
}

function onPreset(param: string, value: string) {
    values.value[param] = value;
    navigate();
}

let timer: ReturnType<typeof setTimeout> | null = null;

function navigate() {
    // Start from the live URL so params owned by OTHER bars / deep links survive.
    const query: Record<string, string> = {};
    new URLSearchParams(window.location.search).forEach((v, k) => {
        query[k] = v;
    });
    for (const c of props.block.controls) {
        const val = values.value[c.param];
        if (val) {
            query[c.param] = val;
        } else {
            delete query[c.param];
        }
    }
    router.get(window.location.pathname, query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function onSearch(param: string, event: Event) {
    values.value[param] = (event.target as HTMLInputElement).value;
    if (timer) {
        clearTimeout(timer);
    }
    timer = setTimeout(navigate, 400); // debounce typing
}

function onSelect(param: string, event: Event) {
    values.value[param] = (event.target as HTMLSelectElement).value;
    navigate();
}
</script>

<template>
    <div
        :class="[
            'flex flex-wrap items-center gap-x-4 gap-y-2 rounded-2xl border px-4 py-3',
            t.surface,
        ]"
    >
        <span
            v-if="block.label"
            :class="[
                'mr-1 self-center text-[11px] tracking-wider uppercase',
                t.textSubtle,
            ]"
        >
            {{ block.label }}
        </span>
        <template v-for="c in block.controls" :key="c.param">
            <label
                v-if="c.label && c.type !== 'date_range'"
                :class="['self-center text-[11px]', t.textMuted]"
                >{{ c.label }}</label
            >
            <input
                v-if="c.type === 'search'"
                type="search"
                :value="values[c.param]"
                :placeholder="c.placeholder ?? 'Search…'"
                :class="[
                    'min-w-[12rem] rounded-full border border-medium bg-surface px-3.5 py-1.5 text-sm',
                    t.text,
                ]"
                @input="onSearch(c.param, $event)"
            />
            <!-- Segmented pill group: a soft rounded track, accent-filled active
                 pill with a lift shadow. The active colour follows the org brand
                 (--sp-accent), so a blue-branded board reads exactly like the
                 executive mockup. -->
            <div
                v-else-if="c.type === 'date_range'"
                class="inline-flex flex-wrap items-center gap-1 rounded-full bg-surface p-1"
            >
                <button
                    v-for="p in presetsFor(c)"
                    :key="p.value"
                    type="button"
                    :class="[
                        'rounded-full px-4 py-1.5 text-sm font-semibold transition-all',
                        values[c.param] === p.value
                            ? 'shadow-sm'
                            : [t.textMuted, 'hover:bg-navy'],
                    ]"
                    :style="
                        values[c.param] === p.value
                            ? {
                                  background: 'var(--sp-accent, #2563eb)',
                                  color: 'var(--sp-accent-contrast, #fff)',
                              }
                            : undefined
                    "
                    @click="onPreset(c.param, p.value)"
                >
                    {{ p.label }}
                </button>
            </div>
            <select
                v-else
                :value="values[c.param]"
                :class="[
                    'rounded-full border border-medium bg-surface px-3.5 py-1.5 text-sm',
                    t.text,
                ]"
                @change="onSelect(c.param, $event)"
            >
                <option value="">
                    {{ c.placeholder ?? word('filter_all') }}
                </option>
                <option
                    v-for="o in c.options ?? []"
                    :key="o.value"
                    :value="o.value"
                >
                    {{ o.label }}
                </option>
            </select>
        </template>
        <span
            v-if="primaryRangeCaption"
            :class="[
                'ml-auto inline-flex items-center gap-1.5 text-xs tabular-nums',
                t.textMuted,
            ]"
        >
            <RuntimeIcon name="clock" :size="14" />
            {{ primaryRangeCaption }}
        </span>
    </div>
</template>
