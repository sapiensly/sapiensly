<script setup lang="ts">
/**
 * One node component for all 9 step types — the differences (icon, color,
 * summary) come from the STEP_CATALOG keyed by data.type. Trying to write
 * 9 near-identical .vue files would just trade clarity for boilerplate.
 *
 * The card is "dumb": clicking it selects the node via the editor's
 * `select()` so the side panel opens. Editing happens in that panel — no
 * inline editing — which keeps every step type's UX consistent.
 */

import { STEP_CATALOG } from '@/lib/appWorkflowStepCatalog';
import type { ManifestStep } from '@/types/appWorkflows';
import { Handle, Position } from '@vue-flow/core';
import * as LucideIcons from '@lucide/vue';
import { computed, inject, type ComputedRef } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps<{
    id: string;
    data: ManifestStep;
    selected?: boolean;
}>();

const { t } = useI18n();

// Provided by AppWorkflowEditor — used by record.* summaries to render a
// friendly object name instead of the raw `obj_01ksj…` id. Nullable
// because StepNode may also render outside the editor in future contexts.
const objectsById = inject<ComputedRef<Map<string, { id: string; slug: string; name: string }>> | null>(
    'appWorkflowObjectsById',
    null,
);

// Defensive: if the step type isn't in the catalog (would mean a manifest
// with a step type we don't know how to render — likely from a future
// schema version), fall back to the log style. Better degraded than
// crashed.
const entry = computed(() => STEP_CATALOG[props.data.type] ?? STEP_CATALOG.log);
// Lucide-vue-next exports icons as named exports — resolve dynamically.
const Icon = computed(() => (LucideIcons as unknown as Record<string, unknown>)[entry.value.icon]);

const label = computed(() => t(entry.value.labelKey));
const summary = computed(() =>
    entry.value.summary(props.data, { objectsById: objectsById?.value }),
);
const displayName = computed(() => props.data.name?.trim() || label.value);
</script>

<template>
    <div
        class="min-w-[220px] max-w-[300px] cursor-pointer rounded-sp-sm border bg-navy p-3 shadow-sp-float transition-colors"
        :class="selected ? 'border-accent-blue' : 'border-soft hover:border-medium'"
        :style="{ borderLeft: `3px solid ${entry.color}` }"
    >
        <Handle type="target" :position="Position.Top" class="!bg-soft" />

        <div class="flex items-center gap-2">
            <component
                :is="Icon"
                v-if="Icon"
                class="size-4 shrink-0"
                :style="{ color: entry.color }"
            />
            <span class="truncate text-sm font-medium text-ink">{{ displayName }}</span>
        </div>
        <div class="mt-1 truncate text-xs text-ink-muted">{{ summary }}</div>

        <Handle type="source" :position="Position.Bottom" class="!bg-soft" />
    </div>
</template>
