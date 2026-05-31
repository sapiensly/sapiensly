<script setup lang="ts">
/**
 * Sidebar of available step types. Click a row to push that step to the
 * end of the workflow. For MVP we skip native drag-from semantics (it
 * works but it's a lot of plumbing for the same UX) — click-to-add is
 * one keystroke + one click and matches the BlockPalette pattern used
 * elsewhere in the codebase.
 */

import { STEP_CATALOG_ORDERED } from '@/lib/appWorkflowStepCatalog';
import type { StepType } from '@/types/appWorkflows';
import * as LucideIcons from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineEmits<{
    (e: 'add', type: StepType): void;
}>();

const icons = LucideIcons as unknown as Record<string, unknown>;
</script>

<template>
    <aside class="flex w-52 shrink-0 flex-col gap-1 border-r border-soft p-3">
        <h3 class="px-1 pb-1 text-xs uppercase tracking-wider text-ink-subtle">
            {{ t('apps.builder.workflows.palette_heading') }}
        </h3>
        <button
            v-for="entry in STEP_CATALOG_ORDERED"
            :key="entry.type"
            type="button"
            @click="$emit('add', entry.type)"
            :title="t(entry.descriptionKey)"
            class="flex items-center gap-2 rounded-xs px-2 py-1.5 text-left text-sm text-ink-muted transition-colors hover:bg-surface hover:text-ink"
        >
            <component
                :is="icons[entry.icon]"
                v-if="icons[entry.icon]"
                class="size-4 shrink-0"
                :style="{ color: entry.color }"
            />
            <span class="truncate">{{ t(entry.labelKey) }}</span>
        </button>
    </aside>
</template>
