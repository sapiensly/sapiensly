<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { inject, ref } from 'vue';
import type { ObjectDef } from '../types/manifest';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';

interface Control {
    param: string;
    type: 'search' | 'select';
    label?: string;
    placeholder?: string;
    options?: { value: string; label: string }[];
}

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
    Object.fromEntries(props.block.controls.map((c) => [c.param, String(pageParams[c.param] ?? '')])),
);

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
    router.get(window.location.pathname, query, { preserveState: true, preserveScroll: true, replace: true });
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
    <div :class="['flex flex-wrap items-end gap-3 rounded-sp-sm border p-3', t.surface]">
        <span v-if="block.label" :class="['mr-1 self-center text-[11px] uppercase tracking-wider', t.textSubtle]">
            {{ block.label }}
        </span>
        <div v-for="c in block.controls" :key="c.param" class="flex flex-col gap-1">
            <label v-if="c.label" :class="['text-[11px]', t.textMuted]">{{ c.label }}</label>
            <input
                v-if="c.type === 'search'"
                type="search"
                :value="values[c.param]"
                :placeholder="c.placeholder ?? 'Search…'"
                :class="['min-w-[12rem] rounded-xs border border-medium bg-surface px-2.5 py-1.5 text-sm', t.text]"
                @input="onSearch(c.param, $event)"
            />
            <select
                v-else
                :value="values[c.param]"
                :class="['rounded-xs border border-medium bg-surface px-2.5 py-1.5 text-sm', t.text]"
                @change="onSelect(c.param, $event)"
            >
                <option value="">{{ c.placeholder ?? 'All' }}</option>
                <option v-for="o in c.options ?? []" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
        </div>
    </div>
</template>
