<script setup lang="ts">
import * as ChatbotController from '@/actions/App/Http/Controllers/ChatbotController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { Chatbot, PaginatedConversations } from '@/types/chatbot';
import { Head, Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, MessageSquare, Star } from 'lucide-vue-next';
import { computed } from 'vue';

interface Props {
    chatbot: Chatbot;
    conversations: PaginatedConversations;
}

const props = defineProps<Props>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Chatbots', href: ChatbotController.index().url },
    { title: props.chatbot.name, href: ChatbotController.show({ chatbot: props.chatbot.id }).url },
    { title: 'Conversations', href: '#' },
]);

const formatDate = (date: string) => {
    return new Date(date).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};
</script>

<template>
    <Head title="Conversations" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-4xl">
                <div class="mb-8 flex items-center justify-between">
                    <Heading
                        title="Conversations"
                        :description="`All conversations for ${chatbot.name}`"
                    />
                    <Button variant="outline" as-child>
                        <Link :href="ChatbotController.show({ chatbot: chatbot.id }).url">
                            Back to Chatbot
                        </Link>
                    </Button>
                </div>

                <div v-if="conversations.data.length === 0" class="rounded-lg border border-dashed p-12 text-center">
                    <MessageSquare class="mx-auto h-12 w-12 text-muted-foreground" />
                    <h3 class="mt-4 text-lg font-medium">No conversations yet</h3>
                    <p class="mt-2 text-sm text-muted-foreground">
                        Embed the widget on your website to start receiving conversations.
                    </p>
                    <Button class="mt-4" as-child>
                        <Link :href="ChatbotController.embed({ chatbot: chatbot.id }).url">
                            Get Embed Code
                        </Link>
                    </Button>
                </div>

                <div v-else class="space-y-4">
                    <Card
                        v-for="conversation in conversations.data"
                        :key="conversation.id"
                        class="cursor-pointer transition-colors hover:border-primary/50"
                    >
                        <Link :href="ChatbotController.conversation({ chatbot: chatbot.id, conversation: conversation.id }).url">
                            <CardHeader>
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-4">
                                        <div
                                            class="flex h-10 w-10 items-center justify-center rounded-full bg-muted text-sm font-medium"
                                        >
                                            {{ conversation.session?.visitor_name?.[0]?.toUpperCase() || '?' }}
                                        </div>
                                        <div>
                                            <CardTitle class="text-base">
                                                {{ conversation.session?.visitor_name || conversation.session?.visitor_email || 'Anonymous Visitor' }}
                                            </CardTitle>
                                            <CardDescription class="flex items-center gap-2">
                                                <span>{{ conversation.messages_count || conversation.message_count }} messages</span>
                                                <span v-if="conversation.session?.visitor_email" class="text-xs">
                                                    &bull; {{ conversation.session.visitor_email }}
                                                </span>
                                            </CardDescription>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <!-- Rating -->
                                        <div v-if="conversation.rating" class="flex items-center gap-1">
                                            <Star class="h-4 w-4 fill-yellow-400 text-yellow-400" />
                                            <span class="text-sm font-medium">{{ conversation.rating }}</span>
                                        </div>

                                        <!-- Status Badge -->
                                        <Badge v-if="conversation.is_resolved" variant="default">
                                            Resolved
                                        </Badge>
                                        <Badge v-else-if="conversation.is_abandoned" variant="secondary">
                                            Abandoned
                                        </Badge>
                                        <Badge v-else variant="outline">
                                            Open
                                        </Badge>

                                        <!-- Date -->
                                        <span class="text-xs text-muted-foreground">
                                            {{ formatDate(conversation.created_at) }}
                                        </span>
                                    </div>
                                </div>
                            </CardHeader>
                        </Link>
                    </Card>

                    <!-- Pagination -->
                    <div v-if="conversations.last_page > 1" class="flex items-center justify-between pt-4">
                        <p class="text-sm text-muted-foreground">
                            Page {{ conversations.current_page }} of {{ conversations.last_page }}
                            ({{ conversations.total }} total)
                        </p>
                        <div class="flex gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="conversations.current_page === 1"
                                as-child
                            >
                                <Link
                                    :href="ChatbotController.conversations({
                                        chatbot: chatbot.id,
                                        query: { page: conversations.current_page - 1 }
                                    }).url"
                                >
                                    <ChevronLeft class="h-4 w-4" />
                                    Previous
                                </Link>
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="conversations.current_page === conversations.last_page"
                                as-child
                            >
                                <Link
                                    :href="ChatbotController.conversations({
                                        chatbot: chatbot.id,
                                        query: { page: conversations.current_page + 1 }
                                    }).url"
                                >
                                    Next
                                    <ChevronRight class="h-4 w-4" />
                                </Link>
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
