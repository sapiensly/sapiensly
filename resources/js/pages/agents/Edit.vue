<script setup lang="ts">
import * as AgentTeamController from '@/actions/App/Http/Controllers/AgentTeamController';
import AgentForm from '@/components/agents/AgentForm.vue';
import PageHeader from '@/components/app-v2/PageHeader.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import KeywordsInput from '@/components/KeywordsInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import type {
    AgentFormData,
    AgentTeam,
    AgentTypeOption,
    ModelOption,
} from '@/types/agents';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Props {
    team: AgentTeam;
    agentTypes: AgentTypeOption[];
    availableModels: ModelOption[];
}

const props = defineProps<Props>();

const agents = ref<AgentFormData[]>(
    props.team.agents?.map((agent) => ({
        type: agent.type,
        name: agent.name,
        description: agent.description ?? '',
        status: agent.status,
        prompt_template: agent.prompt_template ?? '',
        model: agent.model,
        config: agent.config ?? {},
    })) ?? [],
);

const form = useForm({
    name: props.team.name,
    description: props.team.description ?? '',
    keywords: props.team.keywords ?? [],
    status: props.team.status,
    agents: agents.value,
});

const submit = () => {
    form.agents = agents.value;
    form.put(AgentTeamController.update({ agent_team: props.team.id }).url);
};

const updateAgent = (index: number, agent: AgentFormData) => {
    agents.value[index] = agent;
};

const statusOptions = computed(() => [
    { value: 'draft', label: t('common.draft') },
    { value: 'active', label: t('common.active') },
    { value: 'inactive', label: t('common.inactive') },
]);
</script>

<template>
    <Head :title="`${t('agent_teams.edit.title')} ${team.name}`" />

    <AppLayoutV2 :title="t('app_v2.nav.agent_teams')">
        <div class="mx-auto max-w-4xl space-y-6">
            <PageHeader
                :title="`${t('agent_teams.edit.title')} ${team.name}`"
                :description="t('agent_teams.edit.description')"
            />

                <form class="space-y-8" @submit.prevent="submit">
                    <div class="space-y-6">
                        <HeadingSmall
                            :title="t('agent_teams.edit.details_title')"
                            :description="
                                t('agent_teams.edit.details_description')
                            "
                        />

                        <div class="grid gap-4">
                            <div class="grid gap-2">
                                <Label for="name">{{
                                    t('agent_teams.edit.team_name')
                                }}</Label>
                                <Input
                                    id="name"
                                    v-model="form.name"
                                    required
                                    :placeholder="
                                        t(
                                            'agent_teams.edit.team_name_placeholder',
                                        )
                                    "
                                />
                                <InputError :message="form.errors.name" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="description">{{
                                    t('agent_teams.edit.description_label')
                                }}</Label>
                                <Input
                                    id="description"
                                    v-model="form.description"
                                    :placeholder="
                                        t(
                                            'agent_teams.edit.description_placeholder',
                                        )
                                    "
                                />
                                <InputError
                                    :message="form.errors.description"
                                />
                            </div>

                            <div class="grid gap-2">
                                <Label for="keywords">{{
                                    t('agent_teams.edit.keywords_label')
                                }}</Label>
                                <KeywordsInput v-model="form.keywords" />
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        t(
                                            'agent_teams.edit.keywords_description',
                                        )
                                    }}
                                </p>
                                <InputError :message="form.errors.keywords" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="status">{{
                                    t('common.status')
                                }}</Label>
                                <Select v-model="form.status">
                                    <SelectTrigger id="status">
                                        <SelectValue
                                            :placeholder="
                                                t('common.select_status')
                                            "
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="option in statusOptions"
                                            :key="option.value"
                                            :value="option.value"
                                        >
                                            {{ option.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError :message="form.errors.status" />
                            </div>
                        </div>
                    </div>

                    <div class="space-y-6">
                        <HeadingSmall
                            :title="t('agent_teams.edit.agent_config')"
                            :description="
                                t('agent_teams.edit.agent_config_description')
                            "
                        />

                        <AgentForm
                            v-for="(agent, index) in agents"
                            :key="agent.type"
                            :agent="agent"
                            :index="index"
                            :agent-types="agentTypes"
                            :available-models="availableModels"
                            :errors="form.errors"
                            @update:agent="updateAgent(index, $event)"
                        />
                    </div>

                    <div class="flex justify-end gap-4">
                        <Button variant="outline" as-child>
                            <Link
                                :href="
                                    AgentTeamController.show({
                                        agent_team: team.id,
                                    })
                                "
                            >
                                {{ t('common.cancel') }}
                            </Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">
                            {{ t('common.save_changes') }}
                        </Button>
                    </div>
                </form>
        </div>
    </AppLayoutV2>
</template>
