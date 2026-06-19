<script setup lang="ts">
import * as ChatbotController from '@/actions/App/Http/Controllers/ChatbotController';
import PageHeader from '@/components/app-v2/PageHeader.vue';
import SettingsCard from '@/components/admin/SettingsCard.vue';
import InputError from '@/components/InputError.vue';
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
import type { ChatbotConfig, VisibilityOption } from '@/types/chatbot';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Bot, MessageSquare, Palette } from '@lucide/vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Props {
    defaultConfig: ChatbotConfig;
    visibilityOptions: VisibilityOption[];
    canShareWithOrg: boolean;
}

const props = defineProps<Props>();

const form = useForm({
    name: '',
    description: '',
    config: props.defaultConfig,
    allowed_origins: [] as string[],
});

const submit = () => {
    form.post(ChatbotController.store().url);
};
</script>

<template>
    <Head :title="t('chatbots.create.title')" />

    <AppLayoutV2 :title="t('app_v2.nav.chatbots')">
        <div class="mx-auto max-w-5xl space-y-6">
            <PageHeader
                :title="t('chatbots.create.heading')"
                :description="t('chatbots.create.description')"
            />

            <form class="space-y-4" @submit.prevent="submit">
                <!-- Basic info -->
                <SettingsCard
                    :icon="MessageSquare"
                    :title="t('chatbots.create.basic_info')"
                    :description="t('chatbots.create.basic_info_description')"
                >
                    <div class="space-y-1.5">
                        <Label for="name" class="text-xs text-ink-muted">
                            {{ t('common.name') }}
                        </Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            required
                            :placeholder="t('chatbots.create.name_placeholder')"
                            class="h-9 border-medium bg-surface text-sm text-ink placeholder:text-ink-subtle"
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="space-y-1.5">
                        <Label for="description" class="text-xs text-ink-muted">
                            {{ t('chatbots.create.description_label') }}
                        </Label>
                        <Textarea
                            id="description"
                            v-model="form.description"
                            :placeholder="t('chatbots.create.description_placeholder')"
                            rows="3"
                            class="border-medium bg-surface text-sm text-ink placeholder:text-ink-subtle"
                        />
                        <InputError :message="form.errors.description" />
                    </div>
                </SettingsCard>

                <!-- Agents live in the Bot Flow -->
                <SettingsCard
                    :icon="Bot"
                    :title="t('chatbots.create.agents_title')"
                    :description="t('chatbots.create.agents_description')"
                    tint="#a855f7"
                >
                    <p class="text-[11px] text-ink-subtle">
                        {{ t('chatbots.create.agents_note') }}
                    </p>
                </SettingsCard>

                <!-- Appearance -->
                <SettingsCard
                    :icon="Palette"
                    title="Appearance"
                    description="Customize how your chatbot looks"
                    tint="var(--sp-spectrum-indigo)"
                >
                    <div class="space-y-1.5">
                        <Label for="widget_title" class="text-xs text-ink-muted">
                            Widget Title
                        </Label>
                        <Input
                            id="widget_title"
                            v-model="form.config.appearance.widget_title"
                            placeholder="Support"
                            class="h-9 border-medium bg-surface text-sm text-ink placeholder:text-ink-subtle"
                        />
                    </div>

                    <div class="space-y-1.5">
                        <Label for="welcome_message" class="text-xs text-ink-muted">
                            Welcome Message
                        </Label>
                        <Textarea
                            id="welcome_message"
                            v-model="form.config.appearance.welcome_message"
                            placeholder="Hello! How can I help you today?"
                            rows="2"
                            class="border-medium bg-surface text-sm text-ink placeholder:text-ink-subtle"
                        />
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="space-y-1.5">
                            <Label for="primary_color" class="text-xs text-ink-muted">
                                Primary Color
                            </Label>
                            <div class="flex gap-2">
                                <input
                                    id="primary_color"
                                    type="color"
                                    v-model="form.config.appearance.primary_color"
                                    class="h-9 w-12 cursor-pointer rounded-xs border border-medium bg-surface"
                                />
                                <Input
                                    v-model="form.config.appearance.primary_color"
                                    class="h-9 flex-1 border-medium bg-surface text-sm text-ink placeholder:text-ink-subtle"
                                />
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <Label for="background_color" class="text-xs text-ink-muted">
                                Background
                            </Label>
                            <div class="flex gap-2">
                                <input
                                    id="background_color"
                                    type="color"
                                    v-model="form.config.appearance.background_color"
                                    class="h-9 w-12 cursor-pointer rounded-xs border border-medium bg-surface"
                                />
                                <Input
                                    v-model="form.config.appearance.background_color"
                                    class="h-9 flex-1 border-medium bg-surface text-sm text-ink placeholder:text-ink-subtle"
                                />
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <Label for="text_color" class="text-xs text-ink-muted">
                                Text Color
                            </Label>
                            <div class="flex gap-2">
                                <input
                                    id="text_color"
                                    type="color"
                                    v-model="form.config.appearance.text_color"
                                    class="h-9 w-12 cursor-pointer rounded-xs border border-medium bg-surface"
                                />
                                <Input
                                    v-model="form.config.appearance.text_color"
                                    class="h-9 flex-1 border-medium bg-surface text-sm text-ink placeholder:text-ink-subtle"
                                />
                            </div>
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <Label class="text-xs text-ink-muted">Position</Label>
                        <div class="flex gap-1.5">
                            <button
                                type="button"
                                :class="[
                                    'inline-flex items-center rounded-pill border px-3 py-1 text-xs transition-colors',
                                    form.config.appearance.position === 'bottom-right'
                                        ? 'border-accent-blue/40 bg-accent-blue/10 text-ink'
                                        : 'border-medium bg-surface text-ink-muted hover:text-ink',
                                ]"
                                @click="form.config.appearance.position = 'bottom-right'"
                            >
                                Bottom Right
                            </button>
                            <button
                                type="button"
                                :class="[
                                    'inline-flex items-center rounded-pill border px-3 py-1 text-xs transition-colors',
                                    form.config.appearance.position === 'bottom-left'
                                        ? 'border-accent-blue/40 bg-accent-blue/10 text-ink'
                                        : 'border-medium bg-surface text-ink-muted hover:text-ink',
                                ]"
                                @click="form.config.appearance.position = 'bottom-left'"
                            >
                                Bottom Left
                            </button>
                        </div>
                    </div>
                </SettingsCard>

                <!-- Footer actions — pill pair, primary on the right. -->
                <div class="flex items-center justify-end gap-2 pt-2">
                    <Link :href="ChatbotController.index().url">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-surface px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-surface-hover"
                        >
                            {{ t('common.cancel') }}
                        </button>
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:opacity-50"
                    >
                        {{ t('chatbots.index.create_chatbot') }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayoutV2>
</template>
