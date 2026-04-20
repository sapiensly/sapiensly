<script setup lang="ts">
import * as AgentTeamController from '@/actions/App/Http/Controllers/AgentTeamController';
import PageHeader from '@/components/app-v2/PageHeader.vue';
import AgentTeamCard from '@/components/agents/AgentTeamCard.vue';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import type { PaginatedAgentTeams } from '@/types/agents';
import { Head, Link } from '@inertiajs/vue3';
import { Plus, Users } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Props {
    teams: PaginatedAgentTeams;
}

defineProps<Props>();
</script>

<template>
    <Head :title="t('agent_teams.index.title')" />

    <AppLayoutV2 :title="t('app_v2.nav.agent_teams')">
        <div class="space-y-6">
            <PageHeader
                :title="t('agent_teams.index.title')"
                :description="t('agent_teams.index.description')"
            >
                <template #actions>
                    <Link :href="AgentTeamController.create().url">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover"
                        >
                            <Plus class="size-3.5" />
                            {{ t('agent_teams.index.create_team') }}
                        </button>
                    </Link>
                </template>
            </PageHeader>

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

            <div
                v-else
                class="rounded-sp-sm border border-dashed border-soft bg-navy/40 px-6 py-12 text-center"
            >
                <div
                    class="mx-auto flex size-12 items-center justify-center rounded-xs bg-white/5 text-ink-muted"
                >
                    <Users class="size-5" />
                </div>
                <h3 class="mt-4 text-sm font-semibold text-ink">
                    {{ t('agent_teams.index.no_teams') }}
                </h3>
                <p class="mt-1 text-xs text-ink-muted">
                    {{ t('agent_teams.index.no_teams_description') }}
                </p>
                <Link :href="AgentTeamController.create().url" class="mt-4 inline-block">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover"
                    >
                        <Plus class="size-3.5" />
                        {{ t('agent_teams.index.create_first') }}
                    </button>
                </Link>
            </div>
        </div>
    </AppLayoutV2>
</template>
