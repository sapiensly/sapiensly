<script setup lang="ts">
import * as AgentTeamController from '@/actions/App/Http/Controllers/AgentTeamController';
import AgentSelector from '@/components/agents/AgentSelector.vue';
import Heading from '@/components/Heading.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import KeywordsInput from '@/components/KeywordsInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { AgentType, AgentTypeOption, ModelOption } from '@/types/agents';
import { Head, Link, useForm } from '@inertiajs/vue3';
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

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: t('agent_teams.index.title'), href: AgentTeamController.index().url },
    { title: t('agent_teams.create.title'), href: '#' },
]);

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
    return selectedAgents.value.triage &&
           selectedAgents.value.knowledge &&
           selectedAgents.value.action;
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

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-5xl">
                <Heading
                    :title="t('agent_teams.create.heading')"
                    :description="t('agent_teams.create.description')"
                />

                <form class="mt-8 space-y-8" @submit.prevent="submit">
                    <div class="space-y-6">
                        <HeadingSmall
                            :title="t('agent_teams.create.details_title')"
                            :description="t('agent_teams.create.details_description')"
                        />

                        <div class="grid gap-4 max-w-xl">
                            <div class="grid gap-2">
                                <Label for="name">{{ t('agent_teams.create.team_name') }}</Label>
                                <Input
                                    id="name"
                                    v-model="form.name"
                                    required
                                    :placeholder="t('agent_teams.create.team_name_placeholder')"
                                />
                                <InputError :message="form.errors.name" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="description">{{ t('agent_teams.create.description_label') }}</Label>
                                <Textarea
                                    id="description"
                                    v-model="form.description"
                                    :placeholder="t('agent_teams.create.description_placeholder')"
                                    rows="2"
                                />
                                <InputError :message="form.errors.description" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="keywords">{{ t('agent_teams.create.keywords_label') }}</Label>
                                <KeywordsInput v-model="form.keywords" />
                                <p class="text-xs text-muted-foreground">
                                    {{ t('agent_teams.create.keywords_description') }}
                                </p>
                                <InputError :message="form.errors.keywords" />
                            </div>
                        </div>
                    </div>

                    <div class="space-y-6">
                        <HeadingSmall
                            :title="t('agent_teams.create.select_agents')"
                            :description="t('agent_teams.create.select_agents_description')"
                        />

                        <div class="grid gap-4 md:grid-cols-3">
                            <AgentSelector
                                type="triage"
                                :type-info="agentTypeMap.triage"
                                :agents="standaloneAgents.triage"
                                v-model="selectedAgents.triage"
                            />
                            <InputError :message="form.errors['agent_ids.triage']" />

                            <AgentSelector
                                type="knowledge"
                                :type-info="agentTypeMap.knowledge"
                                :agents="standaloneAgents.knowledge"
                                v-model="selectedAgents.knowledge"
                            />
                            <InputError :message="form.errors['agent_ids.knowledge']" />

                            <AgentSelector
                                type="action"
                                :type-info="agentTypeMap.action"
                                :agents="standaloneAgents.action"
                                v-model="selectedAgents.action"
                            />
                            <InputError :message="form.errors['agent_ids.action']" />
                        </div>
                    </div>

                    <div class="flex justify-end gap-4 pt-4 border-t">
                        <Button variant="outline" as-child>
                            <Link :href="AgentTeamController.index().url">
                                {{ t('common.cancel') }}
                            </Link>
                        </Button>
                        <Button
                            type="submit"
                            :disabled="form.processing || !isComplete"
                        >
                            {{ t('agent_teams.create.submit') }}
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
