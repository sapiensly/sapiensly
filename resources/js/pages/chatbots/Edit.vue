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
import type { Chatbot, ChatbotAgent, ChatbotAgentTeam, VisibilityOption } from '@/types/chatbot';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Bot, Users } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Props {
    chatbot: Chatbot;
    agents: ChatbotAgent[];
    agentTeams: ChatbotAgentTeam[];
    visibilityOptions: VisibilityOption[];
    canShareWithOrg: boolean;
}

const props = defineProps<Props>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Chatbots', href: ChatbotController.index().url },
    { title: props.chatbot.name, href: ChatbotController.show({ chatbot: props.chatbot.id }).url },
    { title: 'Edit', href: '#' },
]);

const targetType = ref<'agent' | 'team'>(props.chatbot.agent_id ? 'agent' : 'team');

const form = useForm({
    name: props.chatbot.name,
    description: props.chatbot.description ?? '',
    agent_id: props.chatbot.agent_id,
    agent_team_id: props.chatbot.agent_team_id,
    status: props.chatbot.status,
    visibility: props.chatbot.visibility,
    config: props.chatbot.config,
    allowed_origins: props.chatbot.allowed_origins ?? [],
});

const statusOptions = [
    { value: 'draft', label: 'Draft' },
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
];

const submit = () => {
    form.put(ChatbotController.update({ chatbot: props.chatbot.id }).url);
};
</script>

<template>
    <Head :title="`Edit ${chatbot.name}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-2xl">
                <Heading
                    :title="`Edit ${chatbot.name}`"
                    description="Update your chatbot configuration"
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

                            <div v-if="canShareWithOrg" class="grid gap-2">
                                <Label for="visibility">Visibility</Label>
                                <Select v-model="form.visibility">
                                    <SelectTrigger id="visibility">
                                        <SelectValue placeholder="Select visibility" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="option in visibilityOptions"
                                            :key="option.value"
                                            :value="option.value"
                                        >
                                            {{ option.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError :message="form.errors.visibility" />
                            </div>
                        </div>
                    </div>

                    <!-- Target Selection -->
                    <div class="space-y-6">
                        <HeadingSmall
                            title="Target"
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

                            <div class="grid gap-2">
                                <Label for="placeholder_text">Input Placeholder</Label>
                                <Input
                                    id="placeholder_text"
                                    v-model="form.config.appearance.placeholder_text"
                                    placeholder="Type your message..."
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
                                            class="h-10 w-12 cursor-pointer rounded border"
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
                                            class="h-10 w-12 cursor-pointer rounded border"
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
                                            class="h-10 w-12 cursor-pointer rounded border"
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

                    <!-- Behavior Settings -->
                    <div class="space-y-6">
                        <HeadingSmall
                            title="Behavior"
                            description="Configure widget behavior"
                        />

                        <div class="grid gap-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <Label>Collect Email</Label>
                                    <p class="text-xs text-muted-foreground">
                                        Ask visitors for their email
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    :variant="form.config.behavior.collect_email ? 'default' : 'outline'"
                                    size="sm"
                                    @click="form.config.behavior.collect_email = !form.config.behavior.collect_email"
                                >
                                    {{ form.config.behavior.collect_email ? 'On' : 'Off' }}
                                </Button>
                            </div>

                            <div class="flex items-center justify-between">
                                <div>
                                    <Label>Collect Name</Label>
                                    <p class="text-xs text-muted-foreground">
                                        Ask visitors for their name
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    :variant="form.config.behavior.collect_name ? 'default' : 'outline'"
                                    size="sm"
                                    @click="form.config.behavior.collect_name = !form.config.behavior.collect_name"
                                >
                                    {{ form.config.behavior.collect_name ? 'On' : 'Off' }}
                                </Button>
                            </div>

                            <div class="flex items-center justify-between">
                                <div>
                                    <Label>Show Powered By</Label>
                                    <p class="text-xs text-muted-foreground">
                                        Display "Powered by Sapiensly" badge
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    :variant="form.config.behavior.show_powered_by ? 'default' : 'outline'"
                                    size="sm"
                                    @click="form.config.behavior.show_powered_by = !form.config.behavior.show_powered_by"
                                >
                                    {{ form.config.behavior.show_powered_by ? 'On' : 'Off' }}
                                </Button>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end gap-4">
                        <Button variant="outline" as-child>
                            <Link :href="ChatbotController.show({ chatbot: chatbot.id }).url">
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
