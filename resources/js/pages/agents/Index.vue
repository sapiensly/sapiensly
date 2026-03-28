<script setup lang="ts">
import * as AgentTeamController from '@/actions/App/Http/Controllers/AgentTeamController';
import AgentTeamCard from '@/components/agents/AgentTeamCard.vue';
import EmptyState from '@/components/agents/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { PaginatedAgentTeams } from '@/types/agents';
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Props {
    teams: PaginatedAgentTeams;
}

defineProps<Props>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    {
        title: t('agent_teams.index.title'),
        href: AgentTeamController.index().url,
    },
]);
</script>

<template>
    <Head :title="t('agent_teams.index.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-6xl">
                <div class="mb-8 flex items-center justify-between">
                    <Heading
                        :title="t('agent_teams.index.title')"
                        :description="t('agent_teams.index.description')"
                    />
                    <Button as-child>
                        <Link :href="AgentTeamController.create()">
                            <Plus class="mr-2 h-4 w-4" />
                            {{ t('agent_teams.index.create_team') }}
                        </Link>
                    </Button>
                </div>

                <div
                    v-if="teams.data.length > 0"
                    class="grid gap-4 md:grid-cols-2 lg:grid-cols-3"
                >
                    <AgentTeamCard
                        v-for="team in teams.data"
                        :key="team.id"
                        :team="team"
                    />
                </div>

                <EmptyState
                    v-else
                    :title="t('agent_teams.index.no_teams')"
                    :description="t('agent_teams.index.no_teams_description')"
                    :create-url="AgentTeamController.create()"
                    :create-label="t('agent_teams.index.create_first')"
                />
            </div>
        </div>
    </AppLayout>
</template>
