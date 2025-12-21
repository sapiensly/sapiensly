<script setup lang="ts">
import * as ChatbotController from '@/actions/App/Http/Controllers/ChatbotController';
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
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { ChatbotAgent, ChatbotAgentTeam, ChatbotConfig, VisibilityOption } from '@/types/chatbot';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Bot, Users } from 'lucide-vue-next';
import { ref } from 'vue';

interface Props {
    agents: ChatbotAgent[];
    agentTeams: ChatbotAgentTeam[];
    defaultConfig: ChatbotConfig;
    visibilityOptions: VisibilityOption[];
    canShareWithOrg: boolean;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Chatbots', href: ChatbotController.index().url },
    { title: 'Create', href: '#' },
];

const form = useForm({
    name: '',
    description: '',
    agent_id: null as string | null,
    agent_team_id: null as string | null,
    config: props.defaultConfig,
    allowed_origins: [] as string[],
});

const targetType = ref<'agent' | 'team'>('agent');

const submit = () => {
    form.post(ChatbotController.store().url);
};
</script>

<template>
    <Head title="Create Chatbot" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-2xl">
                <Heading
                    title="Create Chatbot"
                    description="Create a new embeddable chatbot widget"
                />

                <form class="mt-8 space-y-8" @submit.prevent="submit">
                    <!-- Basic Information -->
                    <div class="space-y-6">
                        <HeadingSmall
                            title="Basic Information"
                            description="Name and describe your chatbot"
                        />

                        <div class="grid gap-4">
                            <div class="grid gap-2">
                                <Label for="name">Name</Label>
                                <Input
                                    id="name"
                                    v-model="form.name"
                                    required
                                    placeholder="Customer Support"
                                />
                                <InputError :message="form.errors.name" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="description">Description</Label>
                                <Textarea
                                    id="description"
                                    v-model="form.description"
                                    placeholder="What does this chatbot help with?"
                                    rows="3"
                                />
                                <InputError :message="form.errors.description" />
                            </div>
                        </div>
                    </div>

                    <!-- Target Selection -->
                    <div class="space-y-6">
                        <HeadingSmall
                            title="Select Target"
                            description="Choose which agent or team will power this chatbot"
                        />

                        <div class="grid gap-4">
                            <!-- Target Type Selector -->
                            <div class="flex gap-4">
                                <Button
                                    type="button"
                                    :variant="targetType === 'agent' ? 'default' : 'outline'"
                                    class="flex-1"
                                    @click="targetType = 'agent'; form.agent_team_id = null"
                                >
                                    <Bot class="mr-2 h-4 w-4" />
                                    Single Agent
                                </Button>
                                <Button
                                    type="button"
                                    :variant="targetType === 'team' ? 'default' : 'outline'"
                                    class="flex-1"
                                    @click="targetType = 'team'; form.agent_id = null"
                                >
                                    <Users class="mr-2 h-4 w-4" />
                                    Agent Team
                                </Button>
                            </div>

                            <!-- Agent Selection -->
                            <div v-if="targetType === 'agent'" class="grid gap-2">
                                <Label>Select Agent</Label>
                                <Select v-model="form.agent_id">
                                    <SelectTrigger>
                                        <SelectValue placeholder="Choose an agent" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="agent in agents"
                                            :key="agent.id"
                                            :value="agent.id"
                                        >
                                            {{ agent.name }} ({{ agent.type }})
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <p class="text-xs text-muted-foreground">
                                    Use a single agent for simple use cases
                                </p>
                                <InputError :message="form.errors.agent_id" />
                            </div>

                            <!-- Team Selection -->
                            <div v-if="targetType === 'team'" class="grid gap-2">
                                <Label>Select Agent Team</Label>
                                <Select v-model="form.agent_team_id">
                                    <SelectTrigger>
                                        <SelectValue placeholder="Choose an agent team" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="team in agentTeams"
                                            :key="team.id"
                                            :value="team.id"
                                        >
                                            {{ team.name }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <p class="text-xs text-muted-foreground">
                                    Use a team for orchestrated multi-agent flows
                                </p>
                                <InputError :message="form.errors.agent_team_id" />
                            </div>
                        </div>
                    </div>

                    <!-- Appearance Settings -->
                    <div class="space-y-6">
                        <HeadingSmall
                            title="Appearance"
                            description="Customize how your chatbot looks"
                        />

                        <div class="grid gap-4">
                            <div class="grid gap-2">
                                <Label for="widget_title">Widget Title</Label>
                                <Input
                                    id="widget_title"
                                    v-model="form.config.appearance.widget_title"
                                    placeholder="Support"
                                />
                            </div>

                            <div class="grid gap-2">
                                <Label for="welcome_message">Welcome Message</Label>
                                <Textarea
                                    id="welcome_message"
                                    v-model="form.config.appearance.welcome_message"
                                    placeholder="Hello! How can I help you today?"
                                    rows="2"
                                />
                            </div>

                            <div class="grid gap-4 sm:grid-cols-3">
                                <div class="grid gap-2">
                                    <Label for="primary_color">Primary Color</Label>
                                    <div class="flex gap-2">
                                        <input
                                            id="primary_color"
                                            type="color"
                                            v-model="form.config.appearance.primary_color"
                                            class="h-10 w-12 rounded border cursor-pointer"
                                        />
                                        <Input
                                            v-model="form.config.appearance.primary_color"
                                            class="flex-1"
                                        />
                                    </div>
                                </div>

                                <div class="grid gap-2">
                                    <Label for="background_color">Background</Label>
                                    <div class="flex gap-2">
                                        <input
                                            id="background_color"
                                            type="color"
                                            v-model="form.config.appearance.background_color"
                                            class="h-10 w-12 rounded border cursor-pointer"
                                        />
                                        <Input
                                            v-model="form.config.appearance.background_color"
                                            class="flex-1"
                                        />
                                    </div>
                                </div>

                                <div class="grid gap-2">
                                    <Label for="text_color">Text Color</Label>
                                    <div class="flex gap-2">
                                        <input
                                            id="text_color"
                                            type="color"
                                            v-model="form.config.appearance.text_color"
                                            class="h-10 w-12 rounded border cursor-pointer"
                                        />
                                        <Input
                                            v-model="form.config.appearance.text_color"
                                            class="flex-1"
                                        />
                                    </div>
                                </div>
                            </div>

                            <div class="grid gap-2">
                                <Label>Position</Label>
                                <div class="flex gap-4">
                                    <Button
                                        type="button"
                                        :variant="form.config.appearance.position === 'bottom-right' ? 'default' : 'outline'"
                                        size="sm"
                                        @click="form.config.appearance.position = 'bottom-right'"
                                    >
                                        Bottom Right
                                    </Button>
                                    <Button
                                        type="button"
                                        :variant="form.config.appearance.position === 'bottom-left' ? 'default' : 'outline'"
                                        size="sm"
                                        @click="form.config.appearance.position = 'bottom-left'"
                                    >
                                        Bottom Left
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end gap-4">
                        <Button variant="outline" as-child>
                            <Link :href="ChatbotController.index().url">
                                Cancel
                            </Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">
                            Create Chatbot
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
