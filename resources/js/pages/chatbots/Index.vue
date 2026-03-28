<script setup lang="ts">
import * as ChatbotController from '@/actions/App/Http/Controllers/ChatbotController';
import EmptyState from '@/components/agents/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { PaginatedChatbots } from '@/types/chatbot';
import { Head, Link } from '@inertiajs/vue3';
import { Bot, Code, MessageSquare, Plus, Users } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Props {
    chatbots: PaginatedChatbots;
}

defineProps<Props>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: t('chatbots.index.heading'), href: '#' },
]);

const statusVariant = (status: string) => {
    switch (status) {
        case 'active':
            return 'default';
        case 'inactive':
            return 'secondary';
        default:
            return 'outline';
    }
};
</script>

<template>
    <Head :title="t('chatbots.index.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-6xl">
                <div class="mb-8 flex items-center justify-between">
                    <Heading
                        :title="t('chatbots.index.heading')"
                        :description="t('chatbots.index.description')"
                    />
                    <Button as-child>
                        <Link :href="ChatbotController.create().url">
                            <Plus class="mr-2 h-4 w-4" />
                            {{ t('chatbots.index.new_chatbot') }}
                        </Link>
                    </Button>
                </div>

                <div v-if="chatbots.data.length === 0">
                    <EmptyState
                        :title="t('chatbots.index.no_chatbots')"
                        :description="
                            t('chatbots.index.no_chatbots_description')
                        "
                        :create-url="ChatbotController.create().url"
                        :create-label="t('chatbots.index.create_chatbot')"
                    />
                </div>

                <div v-else class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    <Card
                        v-for="chatbot in chatbots.data"
                        :key="chatbot.id"
                        class="h-full transition-colors hover:border-primary/50"
                    >
                        <Link
                            :href="
                                ChatbotController.show({ chatbot: chatbot.id })
                                    .url
                            "
                        >
                            <CardHeader>
                                <div class="flex items-start justify-between">
                                    <div class="flex items-center gap-2">
                                        <MessageSquare
                                            class="h-5 w-5 text-muted-foreground"
                                        />
                                        <CardTitle class="text-lg">
                                            {{ chatbot.name }}
                                        </CardTitle>
                                    </div>
                                    <Badge
                                        :variant="statusVariant(chatbot.status)"
                                    >
                                        {{ chatbot.status }}
                                    </Badge>
                                </div>
                                <CardDescription v-if="chatbot.description">
                                    {{ chatbot.description }}
                                </CardDescription>
                            </CardHeader>
                        </Link>
                        <CardContent>
                            <div class="flex items-center justify-between">
                                <div
                                    class="flex flex-wrap gap-3 text-sm text-muted-foreground"
                                >
                                    <!-- Target indicator -->
                                    <div
                                        v-if="chatbot.agent"
                                        class="flex items-center gap-1"
                                    >
                                        <Bot class="h-4 w-4" />
                                        {{ chatbot.agent.name }}
                                    </div>
                                    <div
                                        v-else-if="chatbot.agent_team"
                                        class="flex items-center gap-1"
                                    >
                                        <Users class="h-4 w-4" />
                                        {{ chatbot.agent_team.name }}
                                    </div>

                                    <!-- Stats -->
                                    <div
                                        v-if="chatbot.conversations_count"
                                        class="flex items-center gap-1"
                                    >
                                        <MessageSquare class="h-4 w-4" />
                                        {{ chatbot.conversations_count }}
                                    </div>
                                </div>
                                <Button variant="outline" size="sm" as-child>
                                    <Link
                                        :href="
                                            ChatbotController.embed({
                                                chatbot: chatbot.id,
                                            }).url
                                        "
                                    >
                                        <Code class="mr-1 h-4 w-4" />
                                        {{ t('chatbots.index.embed') }}
                                    </Link>
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
