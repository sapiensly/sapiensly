<script setup lang="ts">
import type { AgentHandoffNodeConfig } from '@/types/flows';
import { Handle, Position } from '@vue-flow/core';
import { BookOpen, Brain, Wrench } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    id: string;
    data: AgentHandoffNodeConfig;
}>();

const agentConfig = computed(() => {
    const configs: Record<
        string,
        { icon: typeof BookOpen; color: string; bgColor: string }
    > = {
        knowledge: {
            icon: BookOpen,
            color: 'text-blue-700 dark:text-blue-300',
            bgColor: 'border-blue-500 bg-blue-50 dark:bg-blue-950/40',
        },
        action: {
            icon: Wrench,
            color: 'text-orange-700 dark:text-orange-300',
            bgColor: 'border-orange-500 bg-orange-50 dark:bg-orange-950/40',
        },
        triage_llm: {
            icon: Brain,
            color: 'text-purple-700 dark:text-purple-300',
            bgColor: 'border-purple-500 bg-purple-50 dark:bg-purple-950/40',
        },
    };
    return configs[props.data.target_agent] ?? configs.knowledge;
});

const agentLabel = computed(() => {
    const labels: Record<string, string> = {
        knowledge: t('flows.nodes.agent_knowledge'),
        action: t('flows.nodes.agent_action'),
        triage_llm: t('flows.nodes.agent_triage_llm'),
    };
    return labels[props.data.target_agent] ?? props.data.target_agent;
});
</script>

<template>
    <div
        class="min-w-[180px] rounded-lg border-2 p-3 shadow-sm"
        :class="agentConfig.bgColor"
    >
        <Handle type="target" :position="Position.Top" class="!bg-primary" />

        <div class="mb-1 text-xs font-medium text-muted-foreground">
            {{ t('flows.nodes.agent_handoff') }}
        </div>

        <div class="flex items-center gap-2" :class="agentConfig.color">
            <component :is="agentConfig.icon" class="h-4 w-4" />
            <span class="text-sm font-medium">{{ agentLabel }}</span>
        </div>

        <Handle type="source" :position="Position.Bottom" class="!bg-primary" />
    </div>
</template>
