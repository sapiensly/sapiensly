<script setup lang="ts">
/**
 * Searchable picker for an object_id in the workflow editor's side panel.
 *
 * Replaces the raw text-input-for-`obj_...` we shipped first. The user
 * picks from a list of the App's actual objects (name + slug + id),
 * filtered by a search box. Selecting an item emits the object's id.
 *
 * Open/close UX: click the trigger to toggle the popover. Outside-click
 * or Escape closes. Search field auto-focuses on open. Up/down arrows
 * navigate the filtered list, Enter picks.
 */

import { ChevronDown, Database, Search, X } from 'lucide-vue-next';
import { computed, nextTick, onUnmounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

interface ManifestObject {
    id: string;
    slug: string;
    name: string;
}

const props = defineProps<{
    modelValue: string;
    objects: ManifestObject[];
    placeholder?: string;
    /** Disable when there are no objects to pick from. */
    disabled?: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
}>();

const { t } = useI18n();

const open = ref(false);
const filter = ref('');
const highlightedIndex = ref(0);
const searchInput = ref<HTMLInputElement | null>(null);
const container = ref<HTMLElement | null>(null);

const selectedObject = computed<ManifestObject | null>(
    () => props.objects.find((o) => o.id === props.modelValue) ?? null,
);

const filtered = computed<ManifestObject[]>(() => {
    const q = filter.value.trim().toLowerCase();
    if (q === '') return props.objects;
    return props.objects.filter(
        (o) =>
            o.name.toLowerCase().includes(q)
            || o.slug.toLowerCase().includes(q)
            || o.id.toLowerCase().includes(q),
    );
});

watch(filtered, (rows) => {
    // Keep the highlight in range after filtering.
    if (rows.length === 0) {
        highlightedIndex.value = 0;
    } else if (highlightedIndex.value >= rows.length) {
        highlightedIndex.value = rows.length - 1;
    }
});

watch(open, async (isOpen) => {
    if (isOpen) {
        // Pre-fill the filter empty, focus the search field on next tick.
        filter.value = '';
        highlightedIndex.value = 0;
        await nextTick();
        searchInput.value?.focus();
        document.addEventListener('mousedown', onOutsideClick);
    } else {
        document.removeEventListener('mousedown', onOutsideClick);
    }
});

onUnmounted(() => {
    document.removeEventListener('mousedown', onOutsideClick);
});

function onOutsideClick(event: MouseEvent) {
    if (container.value && !container.value.contains(event.target as Node)) {
        open.value = false;
    }
}

function toggle() {
    if (props.disabled) return;
    open.value = !open.value;
}

function select(obj: ManifestObject) {
    emit('update:modelValue', obj.id);
    open.value = false;
}

function clear() {
    emit('update:modelValue', '');
}

function onSearchKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        event.preventDefault();
        open.value = false;
    } else if (event.key === 'ArrowDown') {
        event.preventDefault();
        if (filtered.value.length > 0) {
            highlightedIndex.value = (highlightedIndex.value + 1) % filtered.value.length;
        }
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        if (filtered.value.length > 0) {
            highlightedIndex.value = (highlightedIndex.value - 1 + filtered.value.length) % filtered.value.length;
        }
    } else if (event.key === 'Enter') {
        event.preventDefault();
        const pick = filtered.value[highlightedIndex.value];
        if (pick) select(pick);
    }
}
</script>

<template>
    <div ref="container" class="relative">
        <!-- Trigger button. Shows the selected object's name + slug, or a
             placeholder when nothing is picked. -->
        <button
            type="button"
            @click="toggle"
            :disabled="disabled || objects.length === 0"
            class="flex h-9 w-full items-center justify-between gap-2 rounded-md border border-medium bg-surface px-2 text-left text-sm text-ink transition-colors hover:border-strong disabled:opacity-50"
        >
            <div class="flex min-w-0 items-center gap-2">
                <Database class="size-3.5 shrink-0 text-ink-muted" />
                <template v-if="selectedObject">
                    <span class="truncate">{{ selectedObject.name }}</span>
                    <span class="truncate font-mono text-xs text-ink-subtle">{{ selectedObject.slug }}</span>
                </template>
                <span v-else class="truncate text-ink-subtle">
                    {{ objects.length === 0
                        ? t('apps.builder.workflows.picker.no_objects')
                        : (placeholder ?? t('apps.builder.workflows.picker.placeholder')) }}
                </span>
            </div>
            <div class="flex shrink-0 items-center gap-1">
                <X
                    v-if="selectedObject"
                    class="size-3.5 text-ink-subtle hover:text-ink"
                    @click.stop="clear"
                />
                <ChevronDown class="size-3.5 text-ink-muted" />
            </div>
        </button>

        <!-- Popover -->
        <div
            v-if="open"
            class="absolute left-0 right-0 top-full z-30 mt-1 max-h-72 overflow-hidden rounded-md border border-soft bg-navy shadow-sp-float"
        >
            <div class="flex items-center gap-2 border-b border-soft px-2 py-1.5">
                <Search class="size-3.5 text-ink-subtle" />
                <input
                    ref="searchInput"
                    v-model="filter"
                    type="text"
                    :placeholder="t('apps.builder.workflows.picker.search')"
                    class="h-7 flex-1 bg-transparent text-sm text-ink placeholder:text-ink-subtle focus:outline-none"
                    @keydown="onSearchKeydown"
                />
            </div>

            <ul v-if="filtered.length > 0" class="max-h-56 overflow-auto py-1">
                <li
                    v-for="(obj, idx) in filtered"
                    :key="obj.id"
                    @mousedown.prevent="select(obj)"
                    @mouseenter="highlightedIndex = idx"
                    :class="[
                        'cursor-pointer px-2 py-1.5 transition-colors',
                        idx === highlightedIndex ? 'bg-accent-blue/15' : 'hover:bg-surface',
                    ]"
                >
                    <div class="flex items-baseline gap-2">
                        <span
                            :class="[
                                'truncate text-sm font-medium',
                                idx === highlightedIndex ? 'text-accent-blue' : 'text-ink',
                            ]"
                        >{{ obj.name }}</span>
                        <span class="truncate font-mono text-xs text-ink-muted">{{ obj.slug }}</span>
                    </div>
                    <div class="truncate font-mono text-xs text-ink-subtle">{{ obj.id }}</div>
                </li>
            </ul>

            <p v-else class="px-3 py-3 text-xs text-ink-muted">
                {{ t('apps.builder.workflows.picker.no_matches') }}
            </p>
        </div>
    </div>
</template>
