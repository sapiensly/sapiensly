<script setup lang="ts">
import * as AgentTeamController from '@/actions/App/Http/Controllers/AgentTeamController';
import AgentSelector from '@/components/agents/AgentSelector.vue';
import SettingsCard from '@/components/admin/SettingsCard.vue';
import PageHeader from '@/components/app-v2/PageHeader.vue';
import InputError from '@/components/InputError.vue';
import KeywordsInput from '@/components/KeywordsInput.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import type { AgentType, AgentTypeOption, ModelOption } from '@/types/agents';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Layers, Users } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface AgentOption {
    id: string;
    name: string;
    description: string | null;
    model: string;
    status: string;
}

interface StandaloneAgents {
    triage: AgentOption[];
    knowledge: AgentOption[];
    action: AgentOption[];
}

interface Props {
    agentTypes: AgentTypeOption[];
    availableModels: ModelOption[];
    standaloneAgents: StandaloneAgents;
}

const props = defineProps<Props>();

const selectedAgents = ref<Record<AgentType, string | null>>({
    triage: null,
    knowledge: null,
    action: null,
});

const form = useForm({
    name: '',
    description: '',
    keywords: [] as string[],
    agent_ids: {} as Record<AgentType, string>,
});

const agentTypeMap = computed(() => {
    const map: Record<string, AgentTypeOption> = {};
    for (const type of props.agentTypes) {
        map[type.value] = type;
    }
    return map;
});

const isComplete = computed(() => {
    return (
        selectedAgents.value.triage &&
        selectedAgents.value.knowledge &&
        selectedAgents.value.action
    );
});

const submit = () => {
    if (!isComplete.value) return;

    form.agent_ids = {
        triage: selectedAgents.value.triage!,
        knowledge: selectedAgents.value.knowledge!,
        action: selectedAgents.value.action!,
    };

    form.post(AgentTeamController.store().url);
};
</script>

<template>
    <Head :title="t('agent_teams.create.title')" />

    <AppLayoutV2 :title="t('app_v2.nav.agent_teams')">
        <div class="mx-auto max-w-5xl space-y-6">
            <PageHeader
                :title="t('agent_teams.create.heading')"
                :description="t('agent_teams.create.description')"
            />

            <form class="space-y-4" @submit.prevent="submit">
                <!-- Team details. -->
                <SettingsCard
                    :icon="Users"
                    :title="t('agent_teams.create.details_title')"
                    :description="t('agent_teams.create.details_description')"
                >
                    <div class="space-y-1.5">
                        <Label for="name">
                            {{ t('agent_teams.create.team_name') }}
                        </Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            required
                            :placeholder="t('agent_teams.create.team_name_placeholder')"
                            class="h-9"
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="space-y-1.5">
                        <Label for="description">
                            {{ t('agent_teams.create.description_label') }}
                        </Label>
                        <Textarea
                            id="description"
                            v-model="form.description"
                            :placeholder="t('agent_teams.create.description_placeholder')"
                            rows="2"
                        />
                        <InputError :message="form.errors.description" />
                    </div>

                    <div class="space-y-1.5">
                        <Label for="keywords">
                            {{ t('agent_teams.create.keywords_label') }}
                        </Label>
                        <KeywordsInput v-model="form.keywords" />
                        <p class="text-[11px] text-ink-subtle">
                            {{ t('agent_teams.create.keywords_description') }}
                        </p>
                        <InputError :message="form.errors.keywords" />
                    </div>
                </SettingsCard>

                <!-- Agent selection — the three-layer triad (triage + knowledge + action). -->
                <SettingsCard
                    :icon="Layers"
                    :title="t('agent_teams.create.select_agents')"
                    :description="t('agent_teams.create.select_agents_description')"
                    tint="var(--sp-spectrum-magenta)"
                >
                    <div class="grid gap-3 md:grid-cols-3">
                        <div class="space-y-1">
                            <AgentSelector
                                type="triage"
                                :type-info="agentTypeMap.triage"
                                :agents="standaloneAgents.triage"
                                v-model="selectedAgents.triage"
                            />
                            <InputError :message="form.errors['agent_ids.triage']" />
                        </div>

                        <div class="space-y-1">
                            <AgentSelector
                                type="knowledge"
                                :type-info="agentTypeMap.knowledge"
                                :agents="standaloneAgents.knowledge"
                                v-model="selectedAgents.knowledge"
                            />
                            <InputError :message="form.errors['agent_ids.knowledge']" />
                        </div>

                        <div class="space-y-1">
                            <AgentSelector
                                type="action"
                                :type-info="agentTypeMap.action"
                                :agents="standaloneAgents.action"
                                v-model="selectedAgents.action"
                            />
                            <InputError :message="form.errors['agent_ids.action']" />
                        </div>
                    </div>
                </SettingsCard>

                <!-- Footer actions. -->
                <div class="flex items-center justify-end gap-2 pt-2">
                    <Link :href="AgentTeamController.index().url">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10"
                        >
                            {{ t('common.cancel') }}
                        </button>
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing || !isComplete"
                        class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:opacity-50"
                    >
                        {{ t('agent_teams.create.submit') }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayoutV2>
</template>
