<script setup lang="ts">
import * as AgentController from '@/actions/App/Http/Controllers/AgentController';
import EmptyState from '@/components/agents/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
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

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: t('agents.index.heading'), href: '#' },
]);

const agentIcon = (type: AgentType) => {
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
};

const statusVariant = (status: string) => {
    switch (status) {
        case 'active':
            return 'default';
        case 'inactive':
            return 'secondary';
        default:
            return 'outline';
    }
};

const filterByType = (type: string | null) => {
    router.get(AgentController.index().url, type ? { type } : {}, {
        preserveState: true,
    });
};

const totalAgents = Object.values(props.agentsByType).reduce(
    (sum, count) => sum + count,
    0,
);
</script>

<template>
    <Head :title="t('agents.index.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-6xl">
                <div class="mb-8 flex items-center justify-between">
                    <Heading
                        :title="t('agents.index.heading')"
                        :description="t('agents.index.description')"
                    />
                    <Button as-child>
                        <Link :href="AgentController.create().url">
                            <Plus class="mr-2 h-4 w-4" />
                            {{ t('agents.index.new_agent') }}
                        </Link>
                    </Button>
                </div>

                <Tabs
                    :default-value="currentType ?? 'all'"
                    class="mb-6"
                    @update:model-value="
                        filterByType($event === 'all' ? null : $event)
                    "
                >
                    <TabsList>
                        <TabsTrigger value="all">
                            {{ t('common.all') }} ({{ totalAgents }})
                        </TabsTrigger>
                        <TabsTrigger
                            v-for="type in agentTypes"
                            :key="type.value"
                            :value="type.value"
                        >
                            <component
                                :is="agentIcon(type.value)"
                                class="mr-2 h-4 w-4"
                            />
                            {{ type.label }} ({{
                                agentsByType[type.value] ?? 0
                            }})
                        </TabsTrigger>
                    </TabsList>
                </Tabs>

                <div v-if="agents.data.length === 0">
                    <EmptyState
                        v-if="!currentType"
                        :title="t('agents.index.no_agents')"
                        :description="t('agents.index.no_agents_description')"
                        :create-url="AgentController.create().url"
                        :create-label="t('agents.index.create_agent')"
                    />
                    <EmptyState
                        v-else
                        :title="t('agents.index.no_agents_filtered')"
                        :description="t('agents.index.no_agents_type')"
                        :create-url="`${AgentController.create().url}?type=${currentType}`"
                        :create-label="t('agents.index.create_agent')"
                    />
                </div>

                <div v-else class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    <Card
                        v-for="agent in agents.data"
                        :key="agent.id"
                        class="h-full transition-colors hover:border-primary/50"
                    >
                        <Link
                            :href="
                                AgentController.show({ agent: agent.id }).url
                            "
                        >
                            <CardHeader>
                                <div class="flex items-start justify-between">
                                    <div class="flex items-center gap-2">
                                        <component
                                            :is="agentIcon(agent.type)"
                                            class="h-5 w-5 text-muted-foreground"
                                        />
                                        <CardTitle class="text-lg">
                                            {{ agent.name }}
                                        </CardTitle>
                                    </div>
                                    <div class="flex items-center gap-1">
                                        <Badge
                                            v-if="agent.team"
                                            variant="secondary"
                                            class="gap-1 text-xs"
                                        >
                                            <Users class="h-3 w-3" />
                                            {{ agent.team.name }}
                                        </Badge>
                                        <Badge
                                            :variant="
                                                statusVariant(agent.status)
                                            "
                                        >
                                            {{ agent.status }}
                                        </Badge>
                                    </div>
                                </div>
                                <CardDescription v-if="agent.description">
                                    {{ agent.description }}
                                </CardDescription>
                            </CardHeader>
                        </Link>
                        <CardContent>
                            <div class="flex items-center justify-between">
                                <div
                                    class="flex flex-wrap gap-4 text-sm text-muted-foreground"
                                >
                                    <div class="flex items-center gap-1">
                                        <Badge
                                            variant="outline"
                                            class="capitalize"
                                        >
                                            {{ agent.type }}
                                        </Badge>
                                    </div>
                                    <div
                                        v-if="agent.knowledge_bases_count"
                                        class="flex items-center gap-1"
                                    >
                                        <Database class="h-4 w-4" />
                                        {{ agent.knowledge_bases_count }}
                                        {{ t('agents.index.kb') }}
                                    </div>
                                    <div
                                        v-if="agent.tools_count"
                                        class="flex items-center gap-1"
                                    >
                                        <Wrench class="h-4 w-4" />
                                        {{ agent.tools_count }}
                                        {{ t('agents.index.tools') }}
                                    </div>
                                </div>
                                <Button variant="outline" size="sm" as-child>
                                    <Link
                                        :href="
                                            AgentController.chat({
                                                agent: agent.id,
                                            }).url
                                        "
                                    >
                                        <MessageSquare class="mr-2 h-4 w-4" />
                                        {{ t('common.test') }}
                                    </Link>
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
