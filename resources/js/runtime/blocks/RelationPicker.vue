<script setup lang="ts">
/**
 * Choosing the record a relation points at.
 *
 * A relation stores an id, and the form used to offer a plain text box for it:
 * the app modelled the link, built the child list and the rollups from it, and
 * then asked somebody to type an id nobody has. The only link anybody could
 * actually make was by creating the child from inside its parent.
 *
 * The list comes from the server, twenty at a time, filtered by what was typed
 * — the whole object cannot be shipped to a browser and a select with four
 * thousand rows is not a control anyway. What is stored is always the id; what
 * is shown is always the name.
 */
import axios from 'axios';
import { computed, onMounted, ref, watch } from 'vue';
import type { FieldDef } from '../types/manifest';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';
import { runtimeWord } from '../words';

interface Option {
    id: string;
    label: string;
}

const props = defineProps<{
    field: FieldDef;
    inputId: string;
    /** An id for a belongs-to, a list of ids for a many-to-many. */
    modelValue: unknown;
    appSlug: string;
    /** The app's locale, so the box speaks the language the app was written in. */
    locale?: string;
}>();

const emit = defineEmits<{ (e: 'update:modelValue', value: unknown): void }>();

const t = themeTokens(useRuntimeTheme());

const multiple = computed(() => props.field.cardinality === 'many_to_many');

const open = ref(false);
const term = ref('');
const options = ref<Option[]>([]);
const truncated = ref(false);
const loading = ref(false);
/**
 * Set when the endpoint is not there to be asked — a public portal does not
 * get one, deliberately. Better to say so than to leave a box that accepts
 * typing and stores something that will never resolve to a record.
 */
const unavailable = ref(false);

/** Labels for ids we have resolved, so a chip survives a list refresh. */
const known = ref<Record<string, string>>({});

const selectedIds = computed<string[]>(() => {
    const v = props.modelValue;
    if (Array.isArray(v))
        return v.filter((x): x is string => typeof x === 'string');
    return typeof v === 'string' && v !== '' ? [v] : [];
});

const selectedLabel = computed(() =>
    selectedIds.value.length === 1
        ? (known.value[selectedIds.value[0]] ?? '…')
        : '',
);

/**
 * Where to ask. The runtime is mounted at /r/{slug} when signed in and at
 * /a/{public_slug} on a public portal; only the URL knows which.
 */
function mount(): string {
    if (typeof window !== 'undefined') {
        const m = window.location.pathname.match(
            /^\/(r|a)\/([a-z0-9][a-z0-9_-]*)/,
        );
        if (m) return `/${m[1]}/${m[2]}`;
    }
    return `/r/${props.appSlug}`;
}

async function fetchOptions(query: string): Promise<void> {
    loading.value = true;
    try {
        const { data } = await axios.get(
            `${mount()}/fields/${props.field.id}/options`,
            { params: query === '' ? {} : { q: query } },
        );
        options.value = data.options ?? [];
        truncated.value = data.truncated === true;
        for (const o of options.value) known.value[o.id] = o.label;
    } catch {
        options.value = [];
        unavailable.value = true;
    } finally {
        loading.value = false;
    }
}

/**
 * Turn the ids already stored into names, on open.
 *
 * Without this an edit form shows a blank box over a real link, which reads as
 * "nothing is set" and invites somebody to set it again.
 */
async function resolveSelected(): Promise<void> {
    const missing = selectedIds.value.filter((id) => !known.value[id]);
    if (missing.length === 0) return;

    try {
        const { data } = await axios.get(
            `${mount()}/fields/${props.field.id}/options`,
            { params: { ids: missing.join(',') } },
        );
        for (const o of (data.options ?? []) as Option[])
            known.value[o.id] = o.label;
    } catch {
        unavailable.value = true;
    }
}

onMounted(resolveSelected);
watch(() => props.modelValue, resolveSelected);

let debounce: ReturnType<typeof setTimeout> | undefined;
watch(term, (value) => {
    if (!open.value) return;
    clearTimeout(debounce);
    debounce = setTimeout(() => void fetchOptions(value), 200);
});

function openList(): void {
    if (unavailable.value) return;
    open.value = true;
    void fetchOptions(term.value);
}

function choose(option: Option): void {
    known.value[option.id] = option.label;

    if (multiple.value) {
        const next = selectedIds.value.includes(option.id)
            ? selectedIds.value.filter((id) => id !== option.id)
            : [...selectedIds.value, option.id];
        emit('update:modelValue', next);
        return;
    }

    emit('update:modelValue', option.id);
    open.value = false;
    term.value = '';
}

function clear(): void {
    emit('update:modelValue', multiple.value ? [] : null);
    term.value = '';
}

function remove(id: string): void {
    emit(
        'update:modelValue',
        selectedIds.value.filter((x) => x !== id),
    );
}

function word(
    key: string,
    replace: Record<string, string | number> = {},
): string {
    return runtimeWord(props.locale, key, replace);
}
</script>

<template>
    <div class="relative">
        <!-- Many-to-many keeps its choices visible as chips: the box below is
             for adding one more, not for showing what is already there. -->
        <div
            v-if="multiple && selectedIds.length"
            class="mb-1.5 flex flex-wrap gap-1.5"
        >
            <span
                v-for="id in selectedIds"
                :key="id"
                :class="[
                    'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs',
                    t.surfaceMuted,
                    t.text,
                ]"
            >
                {{ known[id] ?? '…' }}
                <button
                    type="button"
                    class="opacity-60 hover:opacity-100"
                    @click="remove(id)"
                >
                    ×
                </button>
            </span>
        </div>

        <div class="relative">
            <input
                :id="inputId"
                :value="open || multiple ? term : selectedLabel"
                :placeholder="
                    unavailable
                        ? word('picker_unavailable')
                        : word('picker_search')
                "
                :readonly="unavailable"
                autocomplete="off"
                :class="[
                    'h-9 w-full rounded-md border px-3 pr-8 text-sm',
                    t.surfaceMuted,
                    t.text,
                    unavailable && 'opacity-60',
                ]"
                @focus="openList"
                @input="term = ($event.target as HTMLInputElement).value"
                @keydown.escape="open = false"
            />
            <button
                v-if="!multiple && selectedIds.length && !unavailable"
                type="button"
                class="absolute top-0 right-2 h-9 text-sm opacity-50 hover:opacity-100"
                :aria-label="word('picker_clear')"
                @click="clear"
            >
                ×
            </button>
        </div>

        <!-- Closes on blur, but only after a click on a row has been taken:
             mousedown fires before blur, so choosing beats dismissing. -->
        <div
            v-if="open && !unavailable"
            :class="[
                'absolute z-30 mt-1 max-h-56 w-full overflow-auto rounded-md border shadow-lg',
                t.surface,
            ]"
            @mouseleave="open = false"
        >
            <p v-if="loading" class="px-3 py-2 text-xs opacity-60">…</p>
            <p v-else-if="!options.length" class="px-3 py-2 text-xs opacity-60">
                {{ word('picker_none') }}
            </p>
            <button
                v-for="option in options"
                :key="option.id"
                type="button"
                :class="[
                    'block w-full px-3 py-2 text-left text-sm hover:opacity-80',
                    selectedIds.includes(option.id) && 'font-medium',
                ]"
                @mousedown.prevent="choose(option)"
            >
                {{ option.label }}
            </button>
            <p v-if="truncated" class="px-3 py-1.5 text-[11px] opacity-60">
                {{ word('picker_more') }}
            </p>
        </div>
    </div>
</template>
