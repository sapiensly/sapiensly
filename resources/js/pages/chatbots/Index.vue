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

interface Props {
    chatbots: PaginatedChatbots;
}

defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Chatbots', href: '#' }];

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
    <Head title="Chatbots" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-6xl">
                <div class="mb-8 flex items-center justify-between">
                    <Heading
                        title="Chatbots"
                        description="Manage your embeddable chatbot widgets"
                    />
                    <Button as-child>
                        <Link :href="ChatbotController.create().url">
                            <Plus class="mr-2 h-4 w-4" />
                            New Chatbot
                        </Link>
                    </Button>
                </div>

                <div v-if="chatbots.data.length === 0">
                    <EmptyState
                        title="No chatbots yet"
                        description="Create your first chatbot widget to embed on your website."
                        :create-url="ChatbotController.create().url"
                        create-label="Create Chatbot"
                    />
                </div>

                <div v-else class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    <Card
                        v-for="chatbot in chatbots.data"
                        :key="chatbot.id"
                        class="h-full transition-colors hover:border-primary/50"
                    >
                        <Link :href="ChatbotController.show({ chatbot: chatbot.id }).url">
                            <CardHeader>
                                <div class="flex items-start justify-between">
                                    <div class="flex items-center gap-2">
                                        <MessageSquare class="h-5 w-5 text-muted-foreground" />
                                        <CardTitle class="text-lg">
                                            {{ chatbot.name }}
                                        </CardTitle>
                                    </div>
                                    <Badge :variant="statusVariant(chatbot.status)">
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
                                <div class="flex flex-wrap gap-3 text-sm text-muted-foreground">
                                    <!-- Target indicator -->
                                    <div v-if="chatbot.agent" class="flex items-center gap-1">
                                        <Bot class="h-4 w-4" />
                                        {{ chatbot.agent.name }}
                                    </div>
                                    <div v-else-if="chatbot.agent_team" class="flex items-center gap-1">
                                        <Users class="h-4 w-4" />
                                        {{ chatbot.agent_team.name }}
                                    </div>

                                    <!-- Stats -->
                                    <div v-if="chatbot.conversations_count" class="flex items-center gap-1">
                                        <MessageSquare class="h-4 w-4" />
                                        {{ chatbot.conversations_count }}
                                    </div>
                                </div>
                                <Button variant="outline" size="sm" as-child>
                                    <Link :href="ChatbotController.embed({ chatbot: chatbot.id }).url">
                                        <Code class="mr-1 h-4 w-4" />
                                        Embed
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
