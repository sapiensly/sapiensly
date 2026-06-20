<script setup lang="ts">
import type { AgentHandoffNodeConfig } from '@/types/botFlows';
import { AlertTriangle } from '@lucide/vue';
import { Handle, Position } from '@vue-flow/core';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    id: string;
    data: AgentHandoffNodeConfig;
}>();

const mode = computed<'agent' | 'multi_agent'>(() => {
    if (props.data.mode === 'agent' || props.data.mode === 'multi_agent') {
        return props.data.mode;
    }
    return props.data.layers?.knowledge?.enabled ||
        props.data.layers?.tools?.enabled
        ? 'multi_agent'
        : 'agent';
});

// --- Single-agent mode ---
const singleAgentName = computed(
    () => props.data.layers?.triage?.agent_name ?? null,
);
const singleMissing = computed(
    () => mode.value === 'agent' && !props.data.layers?.triage?.agent_id,
);

// --- Multi-agent mode ---
interface LayerDisplay {
    key: string;
    label: string;
    dotColor: string;
    enabled: boolean;
    agentName: string | null;
}

const layers = computed<LayerDisplay[]>(() => {
    const l = props.data.layers;
    return [
        {
            key: 'triage',
            label: t('botFlows.panel.layer_triage'),
            dotColor: 'bg-purple-500',
            enabled: l?.triage?.enabled ?? true,
            agentName: l?.triage?.agent_name ?? null,
        },
        {
            key: 'knowledge',
            label: t('botFlows.panel.layer_knowledge'),
            dotColor: 'bg-blue-500',
            enabled: l?.knowledge?.enabled ?? false,
            agentName: l?.knowledge?.agent_name ?? null,
        },
        {
            key: 'tools',
            label: t('botFlows.panel.layer_tools'),
            dotColor: 'bg-orange-500',
            enabled: l?.tools?.enabled ?? false,
            agentName: l?.tools?.agent_name ?? null,
        },
    ];
});

const hasWarning = computed(() =>
    mode.value === 'agent'
        ? singleMissing.value
        : layers.value.some((l) => l.enabled && !l.agentName),
);
</script>

<template>
    <div
        class="min-w-[200px] rounded-sp-sm border bg-navy p-3 shadow-sp-float"
        :class="hasWarning ? 'border-sp-warning/60' : 'border-soft'"
    >
        <Handle
            type="target"
            :position="Position.Top"
            class="!bg-accent-blue"
        />

        <div
            class="mb-2 flex items-center gap-1.5 text-xs font-medium text-ink-muted"
        >
            <AlertTriangle
                v-if="hasWarning"
                class="h-3.5 w-3.5 text-sp-warning"
            />
            {{ t('botFlows.nodes.agent_handoff') }}
        </div>

        <!-- Single agent -->
        <div
            v-if="mode === 'agent'"
            class="flex items-center gap-2 text-xs"
            :class="singleMissing ? 'text-ink-subtle' : 'text-ink'"
        >
            <span
                class="inline-block h-2 w-2 shrink-0 rounded-full bg-purple-500"
            />
            <span class="font-medium">{{
                t('botFlows.panel.mode_agent')
            }}</span>
            <span
                v-if="singleAgentName"
                class="max-w-[120px] truncate text-ink-subtle"
            >
                · {{ singleAgentName }}
            </span>
            <span v-else class="text-ink-subtle italic">
                · {{ t('botFlows.panel.layer_select_agent_placeholder') }}
            </span>
        </div>

        <!-- Multi-agent team -->
        <div v-else class="space-y-1.5">
            <div
                v-for="layer in layers"
                :key="layer.key"
                class="flex items-center gap-2 text-xs"
                :class="layer.enabled ? 'text-ink' : 'opacity-35'"
            >
                <span
                    class="inline-block h-2 w-2 shrink-0 rounded-full"
                    :class="layer.dotColor"
                />
                <span class="font-medium">{{ layer.label }}</span>
                <span
                    v-if="layer.enabled && layer.agentName"
                    class="max-w-[120px] truncate text-ink-subtle"
                >
                    · {{ layer.agentName }}
                </span>
            </div>
        </div>

        <Handle
            type="source"
            :position="Position.Bottom"
            class="!bg-accent-blue"
        />
    </div>
</template>
