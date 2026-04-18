<script setup lang="ts">
import type { AgentHandoffNodeConfig } from '@/types/flows';
import { Handle, Position } from '@vue-flow/core';
import { AlertTriangle } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    id: string;
    data: AgentHandoffNodeConfig;
}>();

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
            label: t('flows.panel.layer_triage'),
            dotColor: 'bg-purple-500',
            enabled: l?.triage?.enabled ?? true,
            agentName: l?.triage?.agent_name ?? null,
        },
        {
            key: 'knowledge',
            label: t('flows.panel.layer_knowledge'),
            dotColor: 'bg-blue-500',
            enabled: l?.knowledge?.enabled ?? false,
            agentName: l?.knowledge?.agent_name ?? null,
        },
        {
            key: 'tools',
            label: t('flows.panel.layer_tools'),
            dotColor: 'bg-orange-500',
            enabled: l?.tools?.enabled ?? false,
            agentName: l?.tools?.agent_name ?? null,
        },
    ];
});

const hasWarning = computed(() =>
    layers.value.some((l) => l.enabled && !l.agentName),
);
</script>

<template>
    <div
        class="min-w-[200px] rounded-lg border p-3 shadow-sm"
        :class="hasWarning ? 'border-amber-500 bg-card' : 'border-border bg-card'"
    >
        <Handle type="target" :position="Position.Top" class="!bg-primary" />

        <div class="mb-2 flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
            <AlertTriangle v-if="hasWarning" class="h-3.5 w-3.5 text-amber-500" />
            {{ t('flows.nodes.agent_handoff') }}
        </div>

        <div class="space-y-1.5">
            <div
                v-for="layer in layers"
                :key="layer.key"
                class="flex items-center gap-2 text-xs"
                :class="layer.enabled ? '' : 'opacity-35'"
            >
                <span
                    class="inline-block h-2 w-2 shrink-0 rounded-full"
                    :class="layer.dotColor"
                />
                <span class="font-medium">{{ layer.label }}</span>
                <span
                    v-if="layer.enabled && layer.agentName"
                    class="max-w-[120px] truncate text-muted-foreground"
                >
                    · {{ layer.agentName }}
                </span>
            </div>
        </div>

        <Handle type="source" :position="Position.Bottom" class="!bg-primary" />
    </div>
</template>
