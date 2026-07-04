<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { inject, ref } from 'vue';
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
// range_start() expression function) to a window-start date; 'all' clears it.
const DATE_RANGE_PRESETS: { value: string; label: string }[] = [
    { value: 'today', label: 'Hoy' },
    { value: '7d', label: '7 días' },
    { value: '30d', label: '30 días' },
    { value: '90d', label: '90 días' },
    { value: '1y', label: 'Año' },
    { value: 'all', label: 'Todo' },
];

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
    return c.options && c.options.length ? c.options : DATE_RANGE_PRESETS;
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
            'flex flex-wrap items-end gap-3 rounded-sp-sm border p-3',
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
        <div
            v-for="c in block.controls"
            :key="c.param"
            class="flex flex-col gap-1"
        >
            <label v-if="c.label" :class="['text-[11px]', t.textMuted]">{{
                c.label
            }}</label>
            <input
                v-if="c.type === 'search'"
                type="search"
                :value="values[c.param]"
                :placeholder="c.placeholder ?? 'Search…'"
                :class="[
                    'min-w-[12rem] rounded-xs border border-medium bg-surface px-2.5 py-1.5 text-sm',
                    t.text,
                ]"
                @input="onSearch(c.param, $event)"
            />
            <div
                v-else-if="c.type === 'date_range'"
                class="inline-flex flex-wrap gap-0.5 rounded-xs border border-medium bg-surface p-0.5"
            >
                <button
                    v-for="p in presetsFor(c)"
                    :key="p.value"
                    type="button"
                    :class="[
                        'rounded-xs px-2.5 py-1 text-xs font-medium transition-colors',
                        values[c.param] === p.value
                            ? 'text-white'
                            : [t.textMuted, 'hover:bg-navy'],
                    ]"
                    :style="
                        values[c.param] === p.value
                            ? {
                                  background: 'var(--sp-accent, #10b981)',
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
                    'rounded-xs border border-medium bg-surface px-2.5 py-1.5 text-sm',
                    t.text,
                ]"
                @change="onSelect(c.param, $event)"
            >
                <option value="">{{ c.placeholder ?? 'All' }}</option>
                <option
                    v-for="o in c.options ?? []"
                    :key="o.value"
                    :value="o.value"
                >
                    {{ o.label }}
                </option>
            </select>
        </div>
    </div>
</template>
