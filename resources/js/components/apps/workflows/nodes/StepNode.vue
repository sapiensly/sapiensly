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

import {
    STEP_CATALOG,
    type ConnectorActionSummary,
} from '@/lib/appWorkflowStepCatalog';
import type { ManifestStep } from '@/types/appWorkflows';
import * as LucideIcons from '@lucide/vue';
import { Handle, Position } from '@vue-flow/core';
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
const objectsById = inject<ComputedRef<
    Map<string, { id: string; slug: string; name: string }>
> | null>('appWorkflowObjectsById', null);

// Provided by AppWorkflowEditor — the connector action contracts, so a
// connector.call node can show its integration·action label and the
// read/write effect ribbon.
const connectorActionsById = inject<ComputedRef<
    Map<string, ConnectorActionSummary>
> | null>('appWorkflowConnectorActionsById', null);

// The effect ribbon: read = cool/safe, write = gated (lock). Only shown on
// connector.call nodes whose tool_id resolves to a known action.
const connectorAction = computed<ConnectorActionSummary | undefined>(() => {
    if (props.data.type !== 'connector.call') return undefined;
    const toolId =
        typeof props.data.tool_id === 'string' ? props.data.tool_id : '';
    return toolId ? connectorActionsById?.value?.get(toolId) : undefined;
});

// Defensive: if the step type isn't in the catalog (would mean a manifest
// with a step type we don't know how to render — likely from a future
// schema version), fall back to the log style. Better degraded than
// crashed.
const entry = computed(() => STEP_CATALOG[props.data.type] ?? STEP_CATALOG.log);
// Lucide-vue-next exports icons as named exports — resolve dynamically.
const Icon = computed(
    () => (LucideIcons as unknown as Record<string, unknown>)[entry.value.icon],
);
const LucideLock = (LucideIcons as unknown as Record<string, unknown>).Lock;

const label = computed(() => t(entry.value.labelKey));
const summary = computed(() =>
    entry.value.summary(props.data, {
        objectsById: objectsById?.value,
        connectorActionsById: connectorActionsById?.value,
    }),
);
const displayName = computed(() => props.data.name?.trim() || label.value);
</script>

<template>
    <div
        class="max-w-[300px] min-w-[220px] cursor-pointer rounded-sp-sm border bg-navy p-3 shadow-sp-float transition-colors"
        :class="
            selected ? 'border-accent-blue' : 'border-soft hover:border-medium'
        "
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
            <span class="truncate text-sm font-medium text-ink">{{
                displayName
            }}</span>

            <!-- Blast-radius ribbon: read is cool/safe, write is gated. The
                 same grammar repeats on the plan card and approval gate. -->
            <span
                v-if="connectorAction"
                class="ml-auto inline-flex shrink-0 items-center gap-1 rounded-pill px-1.5 py-0.5 text-[10px] font-medium tracking-wider uppercase"
                :class="
                    connectorAction.effect === 'write'
                        ? 'bg-amber-400/10 text-amber-300'
                        : 'bg-accent-blue/10 text-accent-blue'
                "
                :title="
                    connectorAction.effect === 'write' && !connectorAction.safe
                        ? t('apps.builder.workflows.connector.gated_hint')
                        : ''
                "
            >
                {{
                    connectorAction.effect === 'write'
                        ? t('apps.builder.workflows.connector.effect_write')
                        : t('apps.builder.workflows.connector.effect_read')
                }}
                <component
                    :is="LucideLock"
                    v-if="
                        connectorAction.effect === 'write' &&
                        !connectorAction.safe
                    "
                    class="size-2.5"
                />
            </span>
        </div>
        <div class="mt-1 truncate text-xs text-ink-muted">{{ summary }}</div>

        <Handle type="source" :position="Position.Bottom" class="!bg-soft" />
    </div>
</template>
