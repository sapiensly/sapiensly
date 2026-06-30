<script setup lang="ts">
/**
 * Card grid of existing workflows + a "new workflow" CTA. Click a card →
 * the parent switches to the editor view loaded with that workflow.
 *
 * No outer heading — the parent (Builder.vue) already renders the section
 * heading via `currentViewMeta`. Adding another would visually duplicate
 * it. The "+ Nuevo workflow" button floats at the top-right of the list
 * area instead.
 */

import type { ManifestWorkflow } from '@/types/appWorkflows';
import {
    CalendarClock,
    Clock,
    Hand,
    MessageSquare,
    Pencil,
    Plus,
    Power,
    Trash2,
    Webhook,
} from '@lucide/vue';
import { useI18n } from 'vue-i18n';

defineProps<{
    workflows: ManifestWorkflow[];
}>();

defineEmits<{
    (e: 'select', workflowId: string): void;
    (e: 'create'): void;
}>();

const { t } = useI18n();

const triggerIcons: Record<string, unknown> = {
    manual: Hand,
    'record.created': Plus,
    'record.updated': Pencil,
    'record.deleted': Trash2,
    schedule: Clock,
    'webhook.inbound': Webhook,
    'record.date_reached': CalendarClock,
    'channel.message_received': MessageSquare,
};

// Explicit type → i18n key map. Can't derive it (webhook.inbound's label key
// is `trigger.webhook`, not `trigger.webhook_inbound`).
const triggerLabelKeys: Record<string, string> = {
    manual: 'manual',
    'record.created': 'record_created',
    'record.updated': 'record_updated',
    'record.deleted': 'record_deleted',
    schedule: 'schedule',
    'webhook.inbound': 'webhook',
    'record.date_reached': 'date_reached',
    'channel.message_received': 'channel_message',
};

function triggerLabel(type: string): string {
    return t(
        `apps.builder.workflows.trigger.${triggerLabelKeys[type] ?? type.replace('.', '_')}`,
    );
}
</script>

<template>
    <div class="flex h-full min-h-0 flex-col">
        <div class="flex-1 overflow-auto p-4">
            <!-- "+ Nuevo workflow" CTA: floats right above the grid so it
                 keeps proximity with the list it acts on, without
                 duplicating the section heading the parent already
                 renders. -->
            <div class="mb-3 flex justify-end">
                <button
                    type="button"
                    @click="$emit('create')"
                    class="inline-flex items-center gap-1 rounded-pill bg-accent-blue px-2.5 py-1 text-sm font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover"
                >
                    <Plus class="size-3" />
                    {{ t('apps.builder.workflows.new') }}
                </button>
            </div>

            <p
                v-if="workflows.length === 0"
                class="rounded-sp-sm border border-dashed border-soft bg-surface p-6 text-center text-sm text-ink-muted"
            >
                {{ t('apps.builder.workflows.empty_state') }}
            </p>

            <ul v-else class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                <li
                    v-for="wf in workflows"
                    :key="wf.id"
                    @click="$emit('select', wf.id)"
                    class="group cursor-pointer rounded-sp-sm border border-soft bg-navy p-3 transition-colors hover:border-accent-blue/40"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h3 class="truncate text-sm font-medium text-ink">
                                {{ wf.name }}
                            </h3>
                            <p class="mt-0.5 truncate text-xs text-ink-subtle">
                                {{ wf.slug }}
                            </p>
                        </div>
                        <span
                            v-if="wf.enabled === false"
                            class="rounded-pill border border-medium bg-surface px-1.5 py-0.5 text-xs tracking-wider text-ink-muted uppercase"
                        >
                            <Power class="mr-0.5 inline size-2.5" />
                            {{ t('apps.builder.workflows.disabled') }}
                        </span>
                    </div>

                    <div
                        class="mt-2 flex items-center gap-3 text-xs text-ink-muted"
                    >
                        <span class="inline-flex items-center gap-1">
                            <component
                                :is="triggerIcons[wf.trigger.type] ?? Hand"
                                class="size-3"
                            />
                            {{ triggerLabel(wf.trigger.type) }}
                        </span>
                        <span class="text-ink-subtle">·</span>
                        <span
                            >{{ wf.steps.length }}
                            {{ t('apps.builder.workflows.steps_count') }}</span
                        >
                    </div>
                </li>
            </ul>
        </div>
    </div>
</template>
