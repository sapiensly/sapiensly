<script setup lang="ts">
import type { AgentType, AgentTypeOption } from '@/types/agents';
import { Bot, Brain, Zap } from 'lucide-vue-next';
import type { Component } from 'vue';

defineProps<{
    agentTypes: AgentTypeOption[];
}>();

const emit = defineEmits<{
    select: [type: AgentType];
}>();

function agentIcon(type: AgentType): Component {
    switch (type) {
        case 'triage':
            return Bot;
        case 'knowledge':
            return Brain;
        case 'action':
            return Zap;
        default:
            return Bot;
    }
}

// Same tint map used by the Create / Edit pages so the type icons stay
// consistent across the agent flow (selector → form cards → canvas).
function agentTint(type: AgentType): string {
    switch (type) {
        case 'triage':
            return 'var(--sp-accent-blue)';
        case 'knowledge':
            return 'var(--sp-spectrum-magenta)';
        case 'action':
            return 'var(--sp-warning)';
        default:
            return 'var(--sp-text-secondary)';
    }
}
</script>

<template>
    <div class="grid gap-3 md:grid-cols-3">
        <button
            v-for="type in agentTypes"
            :key="type.value"
            type="button"
            class="flex cursor-pointer flex-col items-start gap-2 rounded-sp-sm border border-soft bg-white/[0.03] p-5 text-left transition-colors hover:border-accent-blue/30 hover:bg-white/[0.06]"
            @click="emit('select', type.value)"
        >
            <!-- Icon tile — tinted by agent type, matching the form card header. -->
            <div
                class="flex size-9 items-center justify-center rounded-xs"
                :style="{
                    backgroundColor: `color-mix(in oklab, ${agentTint(type.value)} 15%, transparent)`,
                    color: agentTint(type.value),
                }"
            >
                <component :is="agentIcon(type.value)" class="size-4" />
            </div>
            <h3 class="text-sm font-semibold text-ink">{{ type.label }}</h3>
            <p class="text-xs text-ink-muted">{{ type.description }}</p>
        </button>
    </div>
</template>
