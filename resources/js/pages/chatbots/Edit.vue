<script setup lang="ts">
import * as BotFlowController from '@/actions/App/Http/Controllers/BotFlowController';
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
import type { Chatbot, VisibilityOption } from '@/types/chatbot';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Workflow } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Props {
    chatbot: Chatbot;
    visibilityOptions: VisibilityOption[];
    canShareWithOrg: boolean;
}

const props = defineProps<Props>();

const form = useForm({
    name: props.chatbot.name,
    description: props.chatbot.description ?? '',
    status: props.chatbot.status,
    visibility: props.chatbot.visibility,
    config: props.chatbot.config,
    allowed_origins: props.chatbot.allowed_origins ?? [],
});

const statusOptions = computed(() => [
    { value: 'draft', label: t('common.draft') },
    { value: 'active', label: t('common.active') },
    { value: 'inactive', label: t('common.inactive') },
]);

const submit = () => {
    form.put(ChatbotController.update({ chatbot: props.chatbot.id }).url);
};
</script>

<template>
    <Head :title="`${t('chatbots.edit.title')} ${chatbot.name}`" />

    <AppLayoutV2 :title="t('app_v2.nav.chatbots')">
        <div class="mx-auto max-w-2xl space-y-6">
            <PageHeader
                :title="`${t('chatbots.edit.title')} ${chatbot.name}`"
                :description="t('chatbots.edit.description')"
            />

                <form class="mt-8 space-y-8" @submit.prevent="submit">
                    <!-- Basic Information -->
                    <div class="space-y-6">
                        <HeadingSmall
                            :title="t('chatbots.edit.basic_info')"
                            :description="
                                t('chatbots.edit.basic_info_description')
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
                                        t('chatbots.edit.name_placeholder')
                                    "
                                />
                                <InputError :message="form.errors.name" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="description">{{
                                    t('chatbots.edit.description_label')
                                }}</Label>
                                <Textarea
                                    id="description"
                                    v-model="form.description"
                                    :placeholder="
                                        t(
                                            'chatbots.edit.description_placeholder',
                                        )
                                    "
                                    rows="3"
                                />
                                <InputError
                                    :message="form.errors.description"
                                />
                            </div>

                            <div class="grid gap-2">
                                <Label for="status">Status</Label>
                                <Select v-model="form.status">
                                    <SelectTrigger id="status">
                                        <SelectValue
                                            placeholder="Select status"
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

                            <div v-if="canShareWithOrg" class="grid gap-2">
                                <Label for="visibility">Visibility</Label>
                                <Select v-model="form.visibility">
                                    <SelectTrigger id="visibility">
                                        <SelectValue
                                            placeholder="Select visibility"
                                        />
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
                            :title="t('chatbots.create.agents_title')"
                            :description="t('chatbots.create.agents_description')"
                        />
                        <p class="text-sm text-ink-subtle">
                            {{ t('chatbots.create.agents_note') }}
                        </p>
                        <Button as-child variant="outline">
                            <Link
                                :href="
                                    BotFlowController.editForChatbot({
                                        chatbot: chatbot.id,
                                    }).url
                                "
                            >
                                <Workflow class="mr-2 h-4 w-4" />
                                {{ t('chatbots.show.edit_flow') }}
                            </Link>
                        </Button>
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

                            <div class="grid gap-2">
                                <Label for="placeholder_text"
                                    >Input Placeholder</Label
                                >
                                <Input
                                    id="placeholder_text"
                                    v-model="
                                        form.config.appearance.placeholder_text
                                    "
                                    placeholder="Type your message..."
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
                                    :variant="
                                        form.config.behavior.collect_email
                                            ? 'default'
                                            : 'outline'
                                    "
                                    size="sm"
                                    @click="
                                        form.config.behavior.collect_email =
                                            !form.config.behavior.collect_email
                                    "
                                >
                                    {{
                                        form.config.behavior.collect_email
                                            ? 'On'
                                            : 'Off'
                                    }}
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
                                    :variant="
                                        form.config.behavior.collect_name
                                            ? 'default'
                                            : 'outline'
                                    "
                                    size="sm"
                                    @click="
                                        form.config.behavior.collect_name =
                                            !form.config.behavior.collect_name
                                    "
                                >
                                    {{
                                        form.config.behavior.collect_name
                                            ? 'On'
                                            : 'Off'
                                    }}
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
                                    :variant="
                                        form.config.behavior.show_powered_by
                                            ? 'default'
                                            : 'outline'
                                    "
                                    size="sm"
                                    @click="
                                        form.config.behavior.show_powered_by =
                                            !form.config.behavior
                                                .show_powered_by
                                    "
                                >
                                    {{
                                        form.config.behavior.show_powered_by
                                            ? 'On'
                                            : 'Off'
                                    }}
                                </Button>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end gap-4">
                        <Button variant="outline" as-child>
                            <Link
                                :href="
                                    ChatbotController.show({
                                        chatbot: chatbot.id,
                                    }).url
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
