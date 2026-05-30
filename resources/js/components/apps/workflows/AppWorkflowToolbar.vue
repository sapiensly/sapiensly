<script setup lang="ts">
import { Check, Loader2, Play, Trash2, X } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

defineProps<{
    workflowName: string;
    isDirty: boolean;
    saving: boolean;
    running: boolean;
    canRun: boolean;
    /** True when the user is in the editor view (vs. the list view). */
    isEditing: boolean;
}>();

const emit = defineEmits<{
    (e: 'save'): void;
    (e: 'run'): void;
    (e: 'discard'): void;
    (e: 'back'): void;
    (e: 'delete'): void;
}>();

const { t } = useI18n();
</script>

<template>
    <header class="flex items-center justify-between gap-2 border-b border-soft px-4 py-2">
        <div class="flex min-w-0 items-center gap-2">
            <button
                v-if="isEditing"
                type="button"
                @click="emit('back')"
                class="rounded-pill px-2 py-1 text-xs uppercase tracking-wider text-ink-muted transition-colors hover:bg-white/5 hover:text-ink"
            >
                ← {{ t('apps.builder.workflows.back_to_list') }}
            </button>
            <span class="truncate text-xs font-medium text-ink">{{ workflowName }}</span>
            <span
                v-if="isDirty"
                class="rounded-pill border border-amber-400/40 bg-amber-400/10 px-1.5 py-0.5 text-xs uppercase tracking-wider text-amber-300"
            >
                {{ t('apps.builder.workflows.dirty') }}
            </span>
        </div>
        <div class="flex shrink-0 items-center gap-1.5">
            <button
                type="button"
                @click="emit('run')"
                :disabled="!canRun || running || isDirty"
                :title="
                    isDirty
                        ? t('apps.builder.workflows.save_first')
                        : !canRun
                            ? t('apps.builder.workflows.run_only_manual')
                            : t('apps.builder.workflows.run_tooltip')
                "
                class="inline-flex items-center gap-1 rounded-pill border border-medium bg-white/5 px-2.5 py-1 text-sm text-ink-muted transition-colors hover:border-strong hover:text-ink disabled:opacity-40"
            >
                <Loader2 v-if="running" class="size-3 animate-spin" />
                <Play v-else class="size-3" />
                {{ running ? t('apps.builder.workflows.running') : t('apps.builder.workflows.run') }}
            </button>
            <button
                v-if="isDirty"
                type="button"
                @click="emit('discard')"
                class="inline-flex items-center gap-1 rounded-pill border border-medium bg-white/5 px-2.5 py-1 text-sm text-ink-muted transition-colors hover:border-strong hover:text-ink"
            >
                <X class="size-3" />
                {{ t('apps.builder.workflows.discard') }}
            </button>
            <button
                type="button"
                @click="emit('save')"
                :disabled="saving || !isDirty"
                class="inline-flex items-center gap-1 rounded-pill bg-accent-blue px-2.5 py-1 text-sm font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:opacity-40"
            >
                <Loader2 v-if="saving" class="size-3 animate-spin" />
                <Check v-else class="size-3" />
                {{ saving ? t('apps.builder.workflows.saving') : t('apps.builder.workflows.save') }}
            </button>
            <button
                v-if="isEditing"
                type="button"
                @click="emit('delete')"
                :title="t('apps.builder.workflows.delete_tooltip')"
                class="inline-flex h-7 w-7 items-center justify-center rounded-pill border border-medium bg-white/5 text-ink-muted transition-colors hover:border-red-400/40 hover:bg-red-400/10 hover:text-red-300"
            >
                <Trash2 class="size-3" />
            </button>
        </div>
    </header>
</template>
