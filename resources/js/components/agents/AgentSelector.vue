<script setup lang="ts">
import * as AgentController from '@/actions/App/Http/Controllers/AgentController';
import type { AgentType, AgentTypeOption } from '@/types/agents';
import { Link } from '@inertiajs/vue3';
import { Bot, Brain, Plus, Zap } from 'lucide-vue-next';
import type { Component } from 'vue';
import { computed } from 'vue';

interface AgentOption {
    id: string;
    name: string;
    description: string | null;
    model: string;
    status: string;
}

interface Props {
    type: AgentType;
    typeInfo: AgentTypeOption;
    agents: AgentOption[];
    modelValue: string | null;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    'update:modelValue': [value: string | null];
}>();

const icons: Record<AgentType, Component> = {
    triage: Bot,
    knowledge: Brain,
    action: Zap,
};

const typeIcon = computed<Component>(() => icons[props.type]);

// Same tint mapping as the standalone-agents + AgentTypeSelector surfaces
// so the triad reads as one consistent visual language.
const tints: Record<AgentType, string> = {
    triage: 'var(--sp-accent-blue)',
    knowledge: 'var(--sp-spectrum-magenta)',
    action: 'var(--sp-warning)',
};

const typeTint = computed(() => tints[props.type]);

const statusTints: Record<string, string> = {
    active: 'var(--sp-success)',
    inactive: 'var(--sp-text-secondary)',
    draft: 'var(--sp-accent-blue)',
};

function statusTint(status: string) {
    return statusTints[status] ?? 'var(--sp-text-secondary)';
}

const createUrl = computed(() => {
    return AgentController.create({ query: { type: props.type } }).url;
});
</script>

<template>
    <div
        :class="[
            'flex flex-col rounded-sp-sm border bg-navy p-4 transition-colors',
            modelValue
                ? 'border-accent-blue/50'
                : 'border-soft hover:border-accent-blue/30',
        ]"
    >
        <!-- Header: tinted icon tile + type name + short description. -->
        <header class="flex items-start gap-3">
            <div
                class="flex size-9 shrink-0 items-center justify-center rounded-xs"
                :style="{
                    backgroundColor: `color-mix(in oklab, ${typeTint} 15%, transparent)`,
                    color: typeTint,
                }"
            >
                <component :is="typeIcon" class="size-4" />
            </div>
            <div class="min-w-0">
                <h3 class="text-sm font-semibold text-ink">
                    {{ typeInfo.label }}
                </h3>
                <p class="mt-0.5 text-[11px] text-ink-subtle">
                    {{ typeInfo.description }}
                </p>
            </div>
        </header>

        <!-- Empty state. -->
        <div v-if="agents.length === 0" class="mt-5 flex-1 space-y-3 text-center">
            <p class="text-xs text-ink-muted">
                No {{ typeInfo.label.toLowerCase() }}s available
            </p>
            <Link :href="createUrl" class="inline-block">
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3 py-1 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10"
                >
                    <Plus class="size-3.5" />
                    Create {{ typeInfo.label }}
                </button>
            </Link>
        </div>

        <!-- Picker — each agent as a selectable row card; checked = accent-blue
             border + tint. Uses a full-area click so the whole row is the hit
             target (no hidden radio). -->
        <div v-else class="mt-4 flex flex-1 flex-col gap-1.5">
            <label
                v-for="agent in agents"
                :key="agent.id"
                :class="[
                    'flex cursor-pointer flex-col gap-1 rounded-xs border px-3 py-2.5 transition-colors',
                    modelValue === agent.id
                        ? 'border-accent-blue/50 bg-accent-blue/10'
                        : 'border-soft bg-white/[0.03] hover:border-accent-blue/30 hover:bg-white/[0.06]',
                ]"
            >
                <input
                    type="radio"
                    class="sr-only"
                    :name="`agent-${type}`"
                    :value="agent.id"
                    :checked="modelValue === agent.id"
                    @change="emit('update:modelValue', agent.id)"
                />
                <div class="flex items-center justify-between gap-2">
                    <span class="truncate text-sm font-medium text-ink">
                        {{ agent.name }}
                    </span>
                    <span
                        class="inline-flex shrink-0 items-center rounded-pill border px-2 py-0.5 text-[10px] font-semibold tracking-wider uppercase"
                        :style="{
                            color: statusTint(agent.status),
                            borderColor: `color-mix(in oklab, ${statusTint(agent.status)} 45%, transparent)`,
                        }"
                    >
                        {{ agent.status }}
                    </span>
                </div>
                <p
                    v-if="agent.description"
                    class="line-clamp-1 text-[11px] text-ink-subtle"
                >
                    {{ agent.description }}
                </p>
            </label>

            <!-- Add-new affordance at the bottom of the picker. -->
            <Link :href="createUrl" class="mt-1">
                <button
                    type="button"
                    class="flex w-full items-center justify-center gap-1.5 rounded-xs border border-dashed border-soft bg-white/[0.02] px-3 py-2 text-xs text-ink-muted transition-colors hover:border-accent-blue/30 hover:bg-white/[0.05] hover:text-ink"
                >
                    <Plus class="size-3.5" />
                    Create New {{ typeInfo.label }}
                </button>
            </Link>
        </div>
    </div>
</template>
