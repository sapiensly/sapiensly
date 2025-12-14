<script setup lang="ts">
import * as AgentTeamController from '@/actions/App/Http/Controllers/AgentTeamController';
import AgentForm from '@/components/agents/AgentForm.vue';
import Heading from '@/components/Heading.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
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
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type {
    AgentFormData,
    AgentTeam,
    AgentTypeOption,
    ModelOption,
} from '@/types/agents';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Props {
    team: AgentTeam;
    agentTypes: AgentTypeOption[];
    availableModels: ModelOption[];
}

const props = defineProps<Props>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Agent Teams', href: AgentTeamController.index().url },
    {
        title: props.team.name,
        href: AgentTeamController.show({ agent_team: props.team.id }).url,
    },
    { title: 'Edit', href: '#' },
]);

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

const statusOptions = [
    { value: 'draft', label: 'Draft' },
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
];
</script>

<template>
    <Head :title="`Edit ${team.name}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-4xl">
                <Heading
                    :title="`Edit ${team.name}`"
                    description="Update your agent team configuration"
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

                            <div class="grid gap-2">
                                <Label for="status">Status</Label>
                                <Select v-model="form.status">
                                    <SelectTrigger id="status">
                                        <SelectValue placeholder="Select status" />
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
                            <Link
                                :href="
                                    AgentTeamController.show({
                                        agent_team: team.id,
                                    })
                                "
                            >
                                Cancel
                            </Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">
                            Save Changes
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
