<script setup lang="ts">
import type { AgentNodeConfig, AgentRole } from '@/types/botFlows';
import { Handle, Position } from '@vue-flow/core';
import { AlertTriangle, Bot } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    id: string;
    data: AgentNodeConfig;
}>();

const roleDot: Record<AgentRole, string> = {
    triage: 'bg-purple-500',
    knowledge: 'bg-blue-500',
    action: 'bg-orange-500',
};

const roleLabel = computed(() => t(`flows.panel.layer_${props.data.role}`));
const unassigned = computed(() => !props.data.agent_id);
</script>

<template>
    <div
        class="min-w-[200px] rounded-sp-sm border bg-navy p-3 shadow-sp-float"
        :class="unassigned ? 'border-sp-warning/60' : 'border-soft'"
    >
        <Handle type="target" :position="Position.Top" class="!bg-accent-blue" />

        <div class="mb-2 flex items-center gap-1.5 text-xs font-medium text-ink-muted">
            <AlertTriangle v-if="unassigned" class="h-3.5 w-3.5 text-sp-warning" />
            <Bot v-else class="h-3.5 w-3.5" />
            {{ t('flows.nodes.agent') }}
        </div>

        <div class="flex items-center gap-2 text-xs">
            <span
                class="inline-block h-2 w-2 shrink-0 rounded-full"
                :class="roleDot[data.role]"
            />
            <span class="font-medium text-ink">{{ roleLabel }}</span>
            <span
                v-if="data.agent_name"
                class="max-w-[120px] truncate text-ink-subtle"
            >
                · {{ data.agent_name }}
            </span>
            <span v-else class="text-sp-warning">
                · {{ t('flows.panel.agent_unassigned') }}
            </span>
        </div>

        <Handle type="source" :position="Position.Bottom" class="!bg-accent-blue" />
    </div>
</template>
