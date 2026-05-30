<script setup lang="ts">
/**
 * The "starting gun" of every workflow. Distinct from regular step nodes:
 *   - No target Handle (it's the entry point — no edge feeds into it).
 *   - Renders trigger.type + a one-liner about the trigger config (e.g.
 *     "On record.created in obj_clientes").
 *   - Always pinned at the top of the canvas.
 */

import type { WorkflowTrigger } from '@/types/appWorkflows';
import { Handle, Position } from '@vue-flow/core';
import { Hand, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { computed, inject, type ComputedRef } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps<{
    id: string;
    data: WorkflowTrigger;
    selected?: boolean;
}>();

const { t } = useI18n();

// Same provide/inject channel as StepNode uses, so the record.* triggers
// can render "Película" instead of the raw obj_01ksj… id.
const objectsById = inject<ComputedRef<Map<string, { id: string; slug: string; name: string }>> | null>(
    'appWorkflowObjectsById',
    null,
);

/** Look up the object's name from its id; fall back to the id (or "—"). */
function objectLabel(objectId: string | undefined): string {
    if (!objectId) return '—';
    const obj = objectsById?.value.get(objectId);
    return obj ? obj.name : objectId;
}

const meta = computed(() => {
    switch (props.data.type) {
        case 'manual':
            return {
                icon: Hand,
                label: t('apps.builder.workflows.trigger.manual'),
                summary: props.data.label || t('apps.builder.workflows.trigger.manual_default'),
                color: '#94a3b8',
            };
        case 'record.created':
            return {
                icon: Plus,
                label: t('apps.builder.workflows.trigger.record_created'),
                summary: objectLabel(props.data.object_id),
                color: '#34d399',
            };
        case 'record.updated':
            return {
                icon: Pencil,
                label: t('apps.builder.workflows.trigger.record_updated'),
                summary: objectLabel(props.data.object_id),
                color: '#fbbf24',
            };
        case 'record.deleted':
            return {
                icon: Trash2,
                label: t('apps.builder.workflows.trigger.record_deleted'),
                summary: objectLabel(props.data.object_id),
                color: '#f87171',
            };
    }
    return { icon: Hand, label: '?', summary: '—', color: '#94a3b8' };
});
</script>

<template>
    <div
        class="min-w-[220px] max-w-[300px] cursor-pointer rounded-sp-sm border bg-navy p-3 shadow-sp-float transition-colors"
        :class="selected ? 'border-accent-blue' : 'border-soft hover:border-medium'"
        :style="{ borderLeft: `3px solid ${meta.color}` }"
    >
        <div class="flex items-center gap-2">
            <component
                :is="meta.icon"
                class="size-4 shrink-0"
                :style="{ color: meta.color }"
            />
            <span class="truncate text-sm font-medium uppercase tracking-wider text-ink-muted">
                {{ meta.label }}
            </span>
        </div>
        <div class="mt-1 truncate text-xs text-ink-muted">{{ meta.summary }}</div>

        <Handle type="source" :position="Position.Bottom" class="!bg-soft" />
    </div>
</template>
