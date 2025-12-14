<script setup lang="ts">
import * as AgentTeamController from '@/actions/App/Http/Controllers/AgentTeamController';
import AgentForm from '@/components/agents/AgentForm.vue';
import Heading from '@/components/Heading.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { AgentFormData, AgentTypeOption, ModelOption } from '@/types/agents';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

interface Props {
    agentTypes: AgentTypeOption[];
    availableModels: ModelOption[];
}

defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Agent Teams', href: AgentTeamController.index().url },
    { title: 'Create Team', href: '#' },
];

const agents = ref<AgentFormData[]>([
    {
        type: 'triage',
        name: 'Triage Agent',
        description: '',
        status: 'draft',
        prompt_template: '',
        model: 'claude-sonnet-4-20250514',
        config: {},
    },
    {
        type: 'knowledge',
        name: 'Knowledge Agent',
        description: '',
        status: 'draft',
        prompt_template: '',
        model: 'claude-sonnet-4-20250514',
        config: {},
    },
    {
        type: 'action',
        name: 'Action Agent',
        description: '',
        status: 'draft',
        prompt_template: '',
        model: 'claude-sonnet-4-20250514',
        config: {},
    },
]);

const form = useForm({
    name: '',
    description: '',
    agents: agents.value,
});

const submit = () => {
    form.agents = agents.value;
    form.post(AgentTeamController.store().url);
};

const updateAgent = (index: number, agent: AgentFormData) => {
    agents.value[index] = agent;
};
</script>

<template>
    <Head title="Create Agent Team" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-4xl">
                <Heading
                    title="Create Agent Team"
                    description="Configure a new team of AI agents for customer service"
                />

                <form class="space-y-8" @submit.prevent="submit">
                    <div class="space-y-6">
                        <HeadingSmall
                            title="Team Details"
                            description="Basic information about your agent team"
                        />

                        <div class="grid gap-4">
                            <div class="grid gap-2">
                                <Label for="name">Team Name</Label>
                                <Input
                                    id="name"
                                    v-model="form.name"
                                    required
                                    placeholder="My Agent Team"
                                />
                                <InputError :message="form.errors.name" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="description">Description</Label>
                                <Input
                                    id="description"
                                    v-model="form.description"
                                    placeholder="Describe what this team does..."
                                />
                                <InputError :message="form.errors.description" />
                            </div>
                        </div>
                    </div>

                    <div class="space-y-6">
                        <HeadingSmall
                            title="Agent Configuration"
                            description="Configure each agent in your team"
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
                            <Link :href="AgentTeamController.index()">
                                Cancel
                            </Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">
                            Create Team
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
