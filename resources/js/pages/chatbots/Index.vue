<script setup lang="ts">
import * as ChatbotController from '@/actions/App/Http/Controllers/ChatbotController';
import PageHeader from '@/components/app-v2/PageHeader.vue';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import type { PaginatedChatbots } from '@/types/chatbot';
import { Head, Link } from '@inertiajs/vue3';
import { Bot, Code, MessageSquare, Plus, Users } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Props {
    chatbots: PaginatedChatbots;
}

defineProps<Props>();

const statusTint: Record<string, string> = {
    active: 'var(--sp-success)',
    inactive: 'var(--sp-text-secondary)',
    draft: 'var(--sp-accent-blue)',
};

function tintFor(status: string) {
    return statusTint[status] ?? 'var(--sp-text-secondary)';
}
</script>

<template>
    <Head :title="t('chatbots.index.title')" />

    <AppLayoutV2 :title="t('app_v2.nav.chatbots')">
        <div class="space-y-6">
            <PageHeader
                :title="t('app_v2.chatbots.heading')"
                :description="t('app_v2.chatbots.description')"
            >
                <template #actions>
                    <Link :href="ChatbotController.create().url">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover"
                        >
                            <Plus class="size-3.5" />
                            {{ t('chatbots.index.new_chatbot') }}
                        </button>
                    </Link>
                </template>
            </PageHeader>

            <div
                v-if="chatbots.data.length === 0"
                class="rounded-sp-sm border border-dashed border-soft bg-navy/40 px-6 py-12 text-center"
            >
                <div
                    class="mx-auto flex size-12 items-center justify-center rounded-xs bg-white/5 text-ink-muted"
                >
                    <MessageSquare class="size-5" />
                </div>
                <h3 class="mt-4 text-sm font-semibold text-ink">
                    {{ t('chatbots.index.no_chatbots') }}
                </h3>
                <p class="mt-1 text-xs text-ink-muted">
                    {{ t('chatbots.index.no_chatbots_description') }}
                </p>
                <Link :href="ChatbotController.create().url" class="mt-4 inline-block">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover"
                    >
                        <Plus class="size-3.5" />
                        {{ t('chatbots.index.create_chatbot') }}
                    </button>
                </Link>
            </div>

            <div
                v-else
                class="grid gap-4 md:grid-cols-2 lg:grid-cols-3"
            >
                <div
                    v-for="chatbot in chatbots.data"
                    :key="chatbot.id"
                    class="flex flex-col rounded-sp-sm border border-soft bg-navy p-5 transition-colors hover:border-accent-blue/30"
                >
                    <Link
                        :href="ChatbotController.show({ chatbot: chatbot.id }).url"
                        class="flex-1"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex items-start gap-3">
                                <div
                                    class="flex size-9 shrink-0 items-center justify-center rounded-xs bg-accent-blue/10 text-accent-blue"
                                >
                                    <MessageSquare class="size-4" />
                                </div>
                                <div class="min-w-0">
                                    <h3 class="truncate text-sm font-semibold text-ink">
                                        {{ chatbot.name }}
                                    </h3>
                                    <p
                                        v-if="chatbot.description"
                                        class="mt-0.5 line-clamp-2 text-xs text-ink-muted"
                                    >
                                        {{ chatbot.description }}
                                    </p>
                                </div>
                            </div>
                            <span
                                class="inline-flex items-center rounded-pill border px-2 py-0.5 text-[10px] font-semibold tracking-wider uppercase"
                                :style="{
                                    color: tintFor(chatbot.status),
                                    borderColor: `color-mix(in oklab, ${tintFor(chatbot.status)} 45%, transparent)`,
                                }"
                            >
                                {{ chatbot.status }}
                            </span>
                        </div>
                    </Link>

                    <div
                        class="mt-4 flex items-center justify-between gap-3 border-t border-soft pt-3"
                    >
                        <div
                            class="flex flex-wrap items-center gap-3 text-[11px] text-ink-subtle"
                        >
                            <span
                                v-if="chatbot.agent"
                                class="inline-flex items-center gap-1"
                            >
                                <Bot class="size-3" />
                                {{ chatbot.agent.name }}
                            </span>
                            <span
                                v-else-if="chatbot.agent_team"
                                class="inline-flex items-center gap-1"
                            >
                                <Users class="size-3" />
                                {{ chatbot.agent_team.name }}
                            </span>
                            <span
                                v-if="chatbot.conversations_count"
                                class="inline-flex items-center gap-1"
                            >
                                <MessageSquare class="size-3" />
                                {{ chatbot.conversations_count }}
                            </span>
                        </div>
                        <Link
                            :href="ChatbotController.embed({ chatbot: chatbot.id }).url"
                            class="inline-flex items-center gap-1 rounded-xs px-2 py-1 text-xs text-ink-muted transition-colors hover:bg-white/5 hover:text-ink"
                        >
                            <Code class="size-3" />
                            {{ t('chatbots.index.embed') }}
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AppLayoutV2>
</template>
