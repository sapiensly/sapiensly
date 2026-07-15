<script setup lang="ts">
/**
 * The "starting gun" of every workflow. Distinct from regular step nodes:
 *   - No target Handle (it's the entry point — no edge feeds into it).
 *   - Renders trigger.type + a one-liner about the trigger config (e.g.
 *     "On record.created in obj_clientes").
 *   - Always pinned at the top of the canvas.
 */

import type { WorkflowTrigger } from '@/types/appWorkflows';
import {
    CalendarClock,
    Clock,
    Hand,
    Mail,
    MessageSquare,
    Pencil,
    Plug,
    Plus,
    RefreshCw,
    Trash2,
    Webhook,
} from '@lucide/vue';
import { Handle, Position } from '@vue-flow/core';
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
const objectsById = inject<ComputedRef<
    Map<string, { id: string; slug: string; name: string }>
> | null>('appWorkflowObjectsById', null);

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
                summary:
                    props.data.label ||
                    t('apps.builder.workflows.trigger.manual_default'),
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
        case 'schedule':
            return {
                icon: Clock,
                label: t('apps.builder.workflows.trigger.schedule'),
                summary: props.data.cron || '—',
                color: '#60a5fa',
            };
        case 'webhook.inbound':
            return {
                icon: Webhook,
                label: t('apps.builder.workflows.trigger.webhook'),
                summary: t('apps.builder.workflows.trigger.webhook_summary'),
                color: '#c084fc',
            };
        case 'record.date_reached':
            return {
                icon: CalendarClock,
                label: t('apps.builder.workflows.trigger.date_reached'),
                summary: objectLabel(props.data.object_id),
                color: '#2dd4bf',
            };
        case 'channel.message_received':
            return {
                icon: MessageSquare,
                label: t('apps.builder.workflows.trigger.channel_message'),
                summary: t(
                    'apps.builder.workflows.trigger.channel_message_summary',
                ),
                color: '#38bdf8',
            };
        case 'integration.event':
            return {
                icon: Plug,
                label: t('apps.builder.workflows.trigger.integration_event'),
                summary: t(
                    'apps.builder.workflows.trigger.integration_event_summary',
                ),
                color: '#a78bfa',
            };
        case 'integration.poll':
            return {
                icon: RefreshCw,
                label: t('apps.builder.workflows.trigger.integration_poll'),
                summary: t(
                    'apps.builder.workflows.trigger.integration_poll_summary',
                ),
                color: '#fb923c',
            };
        case 'email.inbound':
            return {
                icon: Mail,
                label: t('apps.builder.workflows.trigger.email_inbound'),
                summary: t(
                    'apps.builder.workflows.trigger.email_inbound_summary',
                ),
                color: '#f472b6',
            };
    }
    return {
        icon: Hand,
        label: t('apps.builder.workflows.trigger.unknown'),
        summary: '—',
        color: '#94a3b8',
    };
});
</script>

<template>
    <div
        class="max-w-[300px] min-w-[220px] cursor-pointer rounded-sp-sm border bg-navy p-3 shadow-sp-float transition-colors"
        :class="
            selected ? 'border-accent-blue' : 'border-soft hover:border-medium'
        "
        :style="{ borderLeft: `3px solid ${meta.color}` }"
    >
        <div class="flex items-center gap-2">
            <component
                :is="meta.icon"
                class="size-4 shrink-0"
                :style="{ color: meta.color }"
            />
            <span
                class="truncate text-sm font-medium tracking-wider text-ink-muted uppercase"
            >
                {{ meta.label }}
            </span>
        </div>
        <div class="mt-1 truncate text-xs text-ink-muted">
            {{ meta.summary }}
        </div>

        <Handle type="source" :position="Position.Bottom" class="!bg-soft" />
    </div>
</template>
