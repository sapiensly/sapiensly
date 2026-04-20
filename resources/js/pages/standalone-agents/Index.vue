<script setup lang="ts">
import * as AgentController from '@/actions/App/Http/Controllers/AgentController';
import PageHeader from '@/components/app-v2/PageHeader.vue';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import type {
    AgentType,
    AgentTypeOption,
    PaginatedAgents,
} from '@/types/agents';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Bot,
    Brain,
    Database,
    MessageSquare,
    Plus,
    Users,
    Wrench,
    Zap,
} from 'lucide-vue-next';
import type { Component } from 'vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Props {
    agents: PaginatedAgents;
    agentsByType: Record<AgentType, number>;
    currentType: AgentType | null;
    agentTypes: AgentTypeOption[];
}

const props = defineProps<Props>();

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

const statusTint: Record<string, string> = {
    active: 'var(--sp-success)',
    inactive: 'var(--sp-text-secondary)',
    draft: 'var(--sp-accent-blue)',
};

function tintFor(status: string) {
    return statusTint[status] ?? 'var(--sp-text-secondary)';
}

function filterByType(type: string | null) {
    router.get(AgentController.index().url, type ? { type } : {}, {
        preserveState: true,
    });
}

const totalAgents = computed(() =>
    Object.values(props.agentsByType).reduce((sum, count) => sum + count, 0),
);
</script>

<template>
    <Head :title="t('agents.index.title')" />

    <AppLayoutV2 :title="t('app_v2.nav.agents')">
        <div class="space-y-6">
            <PageHeader
                :title="t('app_v2.agents.heading')"
                :description="t('app_v2.agents.description')"
            >
                <template #actions>
                    <Link :href="AgentController.create().url">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover"
                        >
                            <Plus class="size-3.5" />
                            {{ t('agents.index.new_agent') }}
                        </button>
                    </Link>
                </template>
            </PageHeader>

            <!-- Type filter — pill tabs matching admin-v2 ToggleGroup rhythm. -->
            <div class="flex flex-wrap items-center gap-1.5">
                <button
                    type="button"
                    :class="[
                        'inline-flex items-center gap-1.5 rounded-pill border px-3 py-1 text-xs transition-colors',
                        !currentType
                            ? 'border-accent-blue/40 bg-accent-blue/10 text-ink'
                            : 'border-medium bg-white/5 text-ink-muted hover:text-ink',
                    ]"
                    @click="filterByType(null)"
                >
                    {{ t('common.all') }}
                    <span class="text-ink-subtle">({{ totalAgents }})</span>
                </button>
                <button
                    v-for="type in agentTypes"
                    :key="type.value"
                    type="button"
                    :class="[
                        'inline-flex items-center gap-1.5 rounded-pill border px-3 py-1 text-xs transition-colors',
                        currentType === type.value
                            ? 'border-accent-blue/40 bg-accent-blue/10 text-ink'
                            : 'border-medium bg-white/5 text-ink-muted hover:text-ink',
                    ]"
                    @click="filterByType(type.value)"
                >
                    <component :is="agentIcon(type.value)" class="size-3" />
                    {{ type.label }}
                    <span class="text-ink-subtle">
                        ({{ agentsByType[type.value] ?? 0 }})
                    </span>
                </button>
            </div>

            <div
                v-if="agents.data.length === 0"
                class="rounded-sp-sm border border-dashed border-soft bg-navy/40 px-6 py-12 text-center"
            >
                <div
                    class="mx-auto flex size-12 items-center justify-center rounded-xs bg-white/5 text-ink-muted"
                >
                    <Bot class="size-5" />
                </div>
                <h3 class="mt-4 text-sm font-semibold text-ink">
                    {{
                        currentType
                            ? t('agents.index.no_agents_filtered')
                            : t('agents.index.no_agents')
                    }}
                </h3>
                <p class="mt-1 text-xs text-ink-muted">
                    {{
                        currentType
                            ? t('agents.index.no_agents_type')
                            : t('agents.index.no_agents_description')
                    }}
                </p>
                <Link
                    :href="
                        currentType
                            ? `${AgentController.create().url}?type=${currentType}`
                            : AgentController.create().url
                    "
                    class="mt-4 inline-block"
                >
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover"
                    >
                        <Plus class="size-3.5" />
                        {{ t('agents.index.create_agent') }}
                    </button>
                </Link>
            </div>

            <div
                v-else
                class="grid gap-4 md:grid-cols-2 lg:grid-cols-3"
            >
                <div
                    v-for="agent in agents.data"
                    :key="agent.id"
                    class="flex flex-col rounded-sp-sm border border-soft bg-navy p-5 transition-colors hover:border-accent-blue/30"
                >
                    <Link
                        :href="AgentController.show({ agent: agent.id }).url"
                        class="flex-1"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex items-start gap-3">
                                <div
                                    class="flex size-9 shrink-0 items-center justify-center rounded-xs bg-accent-blue/10 text-accent-blue"
                                >
                                    <component
                                        :is="agentIcon(agent.type)"
                                        class="size-4"
                                    />
                                </div>
                                <div class="min-w-0">
                                    <h3 class="truncate text-sm font-semibold text-ink">
                                        {{ agent.name }}
                                    </h3>
                                    <p
                                        v-if="agent.description"
                                        class="mt-0.5 line-clamp-2 text-xs text-ink-muted"
                                    >
                                        {{ agent.description }}
                                    </p>
                                </div>
                            </div>
                            <span
                                class="inline-flex shrink-0 items-center rounded-pill border px-2 py-0.5 text-[10px] font-semibold tracking-wider uppercase"
                                :style="{
                                    color: tintFor(agent.status),
                                    borderColor: `color-mix(in oklab, ${tintFor(agent.status)} 45%, transparent)`,
                                }"
                            >
                                {{ agent.status }}
                            </span>
                        </div>
                    </Link>

                    <div
                        class="mt-4 flex items-center justify-between gap-3 border-t border-soft pt-3"
                    >
                        <div class="flex flex-wrap items-center gap-3 text-[11px] text-ink-subtle">
                            <span
                                class="inline-flex items-center rounded-pill border border-medium px-2 py-0.5 text-[10px] capitalize"
                            >
                                {{ agent.type }}
                            </span>
                            <span
                                v-if="agent.team"
                                class="inline-flex items-center gap-1"
                            >
                                <Users class="size-3" />
                                {{ agent.team.name }}
                            </span>
                            <span
                                v-if="agent.knowledge_bases_count"
                                class="inline-flex items-center gap-1"
                            >
                                <Database class="size-3" />
                                {{ agent.knowledge_bases_count }}
                            </span>
                            <span
                                v-if="agent.tools_count"
                                class="inline-flex items-center gap-1"
                            >
                                <Wrench class="size-3" />
                                {{ agent.tools_count }}
                            </span>
                        </div>
                        <Link
                            :href="AgentController.chat({ agent: agent.id }).url"
                            class="inline-flex items-center gap-1 rounded-xs px-2 py-1 text-xs text-ink-muted transition-colors hover:bg-white/5 hover:text-ink"
                        >
                            <MessageSquare class="size-3" />
                            {{ t('common.test') }}
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AppLayoutV2>
</template>
