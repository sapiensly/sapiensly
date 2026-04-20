<script setup lang="ts">
import * as ChatbotController from '@/actions/App/Http/Controllers/ChatbotController';
import PageHeader from '@/components/app-v2/PageHeader.vue';
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
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import type {
    ChatbotAgent,
    ChatbotAgentTeam,
    ChatbotConfig,
    VisibilityOption,
} from '@/types/chatbot';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Bot, Users } from 'lucide-vue-next';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Props {
    agents: ChatbotAgent[];
    agentTeams: ChatbotAgentTeam[];
    defaultConfig: ChatbotConfig;
    visibilityOptions: VisibilityOption[];
    canShareWithOrg: boolean;
}

const props = defineProps<Props>();

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
    <Head :title="t('chatbots.create.title')" />

    <AppLayoutV2 :title="t('app_v2.nav.chatbots')">
        <div class="mx-auto max-w-2xl space-y-6">
            <PageHeader
                :title="t('chatbots.create.heading')"
                :description="t('chatbots.create.description')"
            />

                <form class="mt-8 space-y-8" @submit.prevent="submit">
                    <!-- Basic Information -->
                    <div class="space-y-6">
                        <HeadingSmall
                            :title="t('chatbots.create.basic_info')"
                            :description="
                                t('chatbots.create.basic_info_description')
                            "
                        />

                        <div class="grid gap-4">
                            <div class="grid gap-2">
                                <Label for="name">{{ t('common.name') }}</Label>
                                <Input
                                    id="name"
                                    v-model="form.name"
                                    required
                                    :placeholder="
                                        t('chatbots.create.name_placeholder')
                                    "
                                />
                                <InputError :message="form.errors.name" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="description">{{
                                    t('chatbots.create.description_label')
                                }}</Label>
                                <Textarea
                                    id="description"
                                    v-model="form.description"
                                    :placeholder="
                                        t(
                                            'chatbots.create.description_placeholder',
                                        )
                                    "
                                    rows="3"
                                />
                                <InputError
                                    :message="form.errors.description"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Target Selection -->
                    <div class="space-y-6">
                        <HeadingSmall
                            :title="t('chatbots.create.select_agent')"
                            :description="
                                t('chatbots.create.select_agent_description')
                            "
                        />

                        <div class="grid gap-4">
                            <!-- Target Type Selector -->
                            <div class="flex gap-4">
                                <Button
                                    type="button"
                                    :variant="
                                        targetType === 'agent'
                                            ? 'default'
                                            : 'outline'
                                    "
                                    class="flex-1"
                                    @click="
                                        targetType = 'agent';
                                        form.agent_team_id = null;
                                    "
                                >
                                    <Bot class="mr-2 h-4 w-4" />
                                    {{ t('chatbots.create.single_agent') }}
                                </Button>
                                <Button
                                    type="button"
                                    :variant="
                                        targetType === 'team'
                                            ? 'default'
                                            : 'outline'
                                    "
                                    class="flex-1"
                                    @click="
                                        targetType = 'team';
                                        form.agent_id = null;
                                    "
                                >
                                    <Users class="mr-2 h-4 w-4" />
                                    {{ t('chatbots.create.agents_team') }}
                                </Button>
                            </div>

                            <!-- Agent Selection -->
                            <div
                                v-if="targetType === 'agent'"
                                class="grid gap-2"
                            >
                                <Label>{{
                                    t('chatbots.create.select_agent')
                                }}</Label>
                                <Select v-model="form.agent_id">
                                    <SelectTrigger>
                                        <SelectValue
                                            :placeholder="
                                                t(
                                                    'chatbots.create.choose_agent',
                                                )
                                            "
                                        />
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
                                    {{ t('chatbots.create.single_agent_note') }}
                                </p>
                                <InputError :message="form.errors.agent_id" />
                            </div>

                            <!-- Team Selection -->
                            <div
                                v-if="targetType === 'team'"
                                class="grid gap-2"
                            >
                                <Label>Select Multi-Agent</Label>
                                <Select v-model="form.agent_team_id">
                                    <SelectTrigger>
                                        <SelectValue
                                            placeholder="Choose a Multi-Agent"
                                        />
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
                                    Use a team for orchestrated multi-agent
                                    flows
                                </p>
                                <InputError
                                    :message="form.errors.agent_team_id"
                                />
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
                                    v-model="
                                        form.config.appearance.widget_title
                                    "
                                    placeholder="Support"
                                />
                            </div>

                            <div class="grid gap-2">
                                <Label for="welcome_message"
                                    >Welcome Message</Label
                                >
                                <Textarea
                                    id="welcome_message"
                                    v-model="
                                        form.config.appearance.welcome_message
                                    "
                                    placeholder="Hello! How can I help you today?"
                                    rows="2"
                                />
                            </div>

                            <div class="grid gap-4 sm:grid-cols-3">
                                <div class="grid gap-2">
                                    <Label for="primary_color"
                                        >Primary Color</Label
                                    >
                                    <div class="flex gap-2">
                                        <input
                                            id="primary_color"
                                            type="color"
                                            v-model="
                                                form.config.appearance
                                                    .primary_color
                                            "
                                            class="h-10 w-12 cursor-pointer rounded border"
                                        />
                                        <Input
                                            v-model="
                                                form.config.appearance
                                                    .primary_color
                                            "
                                            class="flex-1"
                                        />
                                    </div>
                                </div>

                                <div class="grid gap-2">
                                    <Label for="background_color"
                                        >Background</Label
                                    >
                                    <div class="flex gap-2">
                                        <input
                                            id="background_color"
                                            type="color"
                                            v-model="
                                                form.config.appearance
                                                    .background_color
                                            "
                                            class="h-10 w-12 cursor-pointer rounded border"
                                        />
                                        <Input
                                            v-model="
                                                form.config.appearance
                                                    .background_color
                                            "
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
                                            v-model="
                                                form.config.appearance
                                                    .text_color
                                            "
                                            class="h-10 w-12 cursor-pointer rounded border"
                                        />
                                        <Input
                                            v-model="
                                                form.config.appearance
                                                    .text_color
                                            "
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
                                        :variant="
                                            form.config.appearance.position ===
                                            'bottom-right'
                                                ? 'default'
                                                : 'outline'
                                        "
                                        size="sm"
                                        @click="
                                            form.config.appearance.position =
                                                'bottom-right'
                                        "
                                    >
                                        Bottom Right
                                    </Button>
                                    <Button
                                        type="button"
                                        :variant="
                                            form.config.appearance.position ===
                                            'bottom-left'
                                                ? 'default'
                                                : 'outline'
                                        "
                                        size="sm"
                                        @click="
                                            form.config.appearance.position =
                                                'bottom-left'
                                        "
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
                                {{ t('common.cancel') }}
                            </Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">
                            {{ t('chatbots.index.create_chatbot') }}
                        </Button>
                    </div>
                </form>
        </div>
    </AppLayoutV2>
</template>
