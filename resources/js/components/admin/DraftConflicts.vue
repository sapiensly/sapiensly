<script setup lang="ts">
import { AlertTriangle } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * The review a website draft has to pass before it can replace anything.
 *
 * Fields the user left empty are filled by the caller without asking — that is
 * not replacing. Everything shown here is a field the user already wrote, where
 * the draft disagrees, and nothing changes until they pick a side. "Keep mine"
 * is the default by construction: doing nothing leaves the form untouched.
 *
 * Shared by the Contextbook and the Brandbook so both books behave identically.
 */
interface Entry {
    field: string;
    status: string;
    current: unknown;
    proposed: unknown;
}

const props = defineProps<{
    entries: Entry[];
    /** field → human label. A field with no label falls back to its own name. */
    labels: Record<string, string>;
    /** Optional per-field renderer for values that are not plain strings. */
    format?: (field: string, value: unknown) => string;
}>();

const emit = defineEmits<{
    (e: 'accept', field: string, value: unknown): void;
    (e: 'dismiss', field: string): void;
}>();

const { t } = useI18n();

const conflicts = computed(() =>
    props.entries.filter((entry) => entry.status === 'conflict'),
);

function label(field: string): string {
    return props.labels[field] ?? field;
}

function show(field: string, value: unknown): string {
    if (props.format) return props.format(field, value);
    if (value === null || value === undefined || value === '') return '—';
    if (Array.isArray(value)) return `${value.length} ${t('draft.items')}`;
    return String(value);
}
</script>

<template>
    <div v-if="conflicts.length" class="space-y-3">
        <div class="flex items-start gap-2">
            <AlertTriangle class="text-accent-amber mt-0.5 size-4 shrink-0" />
            <p class="text-xs text-ink-muted">
                {{ t('draft.conflicts_hint', { count: conflicts.length }) }}
            </p>
        </div>

        <div
            v-for="entry in conflicts"
            :key="entry.field"
            class="space-y-2 rounded-sp-sm border border-soft p-3"
        >
            <p class="text-xs font-medium text-ink">{{ label(entry.field) }}</p>

            <div class="grid gap-2 sm:grid-cols-2">
                <div class="space-y-1">
                    <p
                        class="text-[11px] tracking-wide text-ink-muted uppercase"
                    >
                        {{ t('draft.yours') }}
                    </p>
                    <p class="text-xs break-words text-ink">
                        {{ show(entry.field, entry.current) }}
                    </p>
                </div>
                <div class="space-y-1">
                    <p
                        class="text-[11px] tracking-wide text-ink-muted uppercase"
                    >
                        {{ t('draft.from_site') }}
                    </p>
                    <p class="text-xs break-words text-ink">
                        {{ show(entry.field, entry.proposed) }}
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-2 pt-1">
                <button
                    type="button"
                    class="h-8 rounded-xs border border-soft px-3 text-xs text-ink-muted transition-colors hover:bg-surface hover:text-ink"
                    @click="emit('dismiss', entry.field)"
                >
                    {{ t('draft.keep_mine') }}
                </button>
                <button
                    type="button"
                    class="h-8 rounded-xs bg-accent-blue px-3 text-xs font-medium text-white transition-opacity hover:opacity-90"
                    @click="emit('accept', entry.field, entry.proposed)"
                >
                    {{ t('draft.use_theirs') }}
                </button>
            </div>
        </div>
    </div>
</template>
