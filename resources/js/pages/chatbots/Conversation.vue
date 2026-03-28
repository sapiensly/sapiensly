<script setup lang="ts">
import * as ChatbotController from '@/actions/App/Http/Controllers/ChatbotController';
import Heading from '@/components/Heading.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type {
    Chatbot,
    WidgetConversation,
    WidgetMessage,
} from '@/types/chatbot';
import { Head, Link } from '@inertiajs/vue3';
import { Bot, Clock, Mail, MessageSquare, Star, User } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface ConversationWithMessages extends WidgetConversation {
    messages: WidgetMessage[];
}

interface Props {
    chatbot: Chatbot;
    conversation: ConversationWithMessages;
}

const props = defineProps<Props>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: t('chatbots.index.heading'), href: ChatbotController.index().url },
    {
        title: props.chatbot.name,
        href: ChatbotController.show({ chatbot: props.chatbot.id }).url,
    },
    {
        title: t('chatbots.conversations.title'),
        href: ChatbotController.conversations({ chatbot: props.chatbot.id })
            .url,
    },
    { title: t('chatbots.conversation.title'), href: '#' },
]);

const formatDate = (date: string) => {
    return new Date(date).toLocaleDateString('en-US', {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const formatTime = (date: string) => {
    return new Date(date).toLocaleTimeString('en-US', {
        hour: '2-digit',
        minute: '2-digit',
    });
};
</script>

<template>
    <Head :title="t('chatbots.conversation.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-4xl">
                <div class="mb-8 flex items-start justify-between">
                    <div>
                        <div class="mb-2 flex items-center gap-3">
                            <Heading
                                :title="t('chatbots.conversation.heading')"
                            />
                            <Badge
                                v-if="conversation.is_resolved"
                                variant="default"
                            >
                                Resolved
                            </Badge>
                            <Badge
                                v-else-if="conversation.is_abandoned"
                                variant="secondary"
                            >
                                Abandoned
                            </Badge>
                            <Badge v-else variant="outline"> Open </Badge>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            {{ t('chatbots.conversation.started') }}
                            {{ formatDate(conversation.created_at) }}
                        </p>
                    </div>
                    <Button variant="outline" as-child>
                        <Link
                            :href="
                                ChatbotController.conversations({
                                    chatbot: chatbot.id,
                                }).url
                            "
                        >
                            {{ t('chatbots.conversation.back') }}
                        </Link>
                    </Button>
                </div>

                <div class="grid gap-6 lg:grid-cols-3">
                    <!-- Visitor Info Sidebar -->
                    <div class="space-y-4 lg:col-span-1">
                        <Card>
                            <CardHeader>
                                <CardTitle class="text-base">{{
                                    t('chatbots.conversation.visitor_info')
                                }}</CardTitle>
                            </CardHeader>
                            <CardContent class="space-y-4">
                                <div class="flex items-center gap-3">
                                    <div
                                        class="flex h-12 w-12 items-center justify-center rounded-full bg-muted text-lg font-medium"
                                    >
                                        {{
                                            conversation.session?.visitor_name?.[0]?.toUpperCase() ||
                                            '?'
                                        }}
                                    </div>
                                    <div>
                                        <p class="font-medium">
                                            {{
                                                conversation.session
                                                    ?.visitor_name ||
                                                t(
                                                    'chatbots.conversation.anonymous',
                                                )
                                            }}
                                        </p>
                                        <p
                                            v-if="
                                                conversation.session
                                                    ?.visitor_email
                                            "
                                            class="text-sm text-muted-foreground"
                                        >
                                            {{
                                                conversation.session
                                                    .visitor_email
                                            }}
                                        </p>
                                    </div>
                                </div>

                                <div class="space-y-3 text-sm">
                                    <div
                                        v-if="
                                            conversation.session?.visitor_email
                                        "
                                        class="flex items-center gap-2"
                                    >
                                        <Mail
                                            class="h-4 w-4 text-muted-foreground"
                                        />
                                        <span>{{
                                            conversation.session.visitor_email
                                        }}</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <MessageSquare
                                            class="h-4 w-4 text-muted-foreground"
                                        />
                                        <span
                                            >{{ conversation.message_count }}
                                            {{
                                                t(
                                                    'chatbots.conversations.messages',
                                                )
                                            }}</span
                                        >
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <Clock
                                            class="h-4 w-4 text-muted-foreground"
                                        />
                                        <span>{{
                                            formatDate(conversation.created_at)
                                        }}</span>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <!-- Rating & Feedback -->
                        <Card
                            v-if="conversation.rating || conversation.feedback"
                        >
                            <CardHeader>
                                <CardTitle class="text-base">{{
                                    t('chatbots.conversation.feedback')
                                }}</CardTitle>
                            </CardHeader>
                            <CardContent class="space-y-3">
                                <div
                                    v-if="conversation.rating"
                                    class="flex items-center gap-2"
                                >
                                    <div class="flex">
                                        <Star
                                            v-for="i in 5"
                                            :key="i"
                                            class="h-5 w-5"
                                            :class="
                                                i <= conversation.rating
                                                    ? 'fill-yellow-400 text-yellow-400'
                                                    : 'text-muted-foreground'
                                            "
                                        />
                                    </div>
                                    <span class="text-sm font-medium"
                                        >{{ conversation.rating
                                        }}{{
                                            t('chatbots.conversation.out_of_5')
                                        }}</span
                                    >
                                </div>
                                <p
                                    v-if="conversation.feedback"
                                    class="text-sm text-muted-foreground"
                                >
                                    "{{ conversation.feedback }}"
                                </p>
                            </CardContent>
                        </Card>
                    </div>

                    <!-- Messages -->
                    <div class="lg:col-span-2">
                        <HeadingSmall
                            title="Messages"
                            description="Full conversation transcript"
                        />

                        <div class="mt-4 space-y-4">
                            <div
                                v-for="message in conversation.messages"
                                :key="message.id"
                                class="flex gap-3"
                                :class="
                                    message.role === 'user'
                                        ? 'flex-row-reverse'
                                        : ''
                                "
                            >
                                <div
                                    class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full"
                                    :class="
                                        message.role === 'user'
                                            ? 'bg-primary text-primary-foreground'
                                            : 'bg-muted'
                                    "
                                >
                                    <User
                                        v-if="message.role === 'user'"
                                        class="h-4 w-4"
                                    />
                                    <Bot v-else class="h-4 w-4" />
                                </div>
                                <div
                                    class="flex max-w-[80%] flex-col gap-1"
                                    :class="
                                        message.role === 'user'
                                            ? 'items-end'
                                            : 'items-start'
                                    "
                                >
                                    <div
                                        class="rounded-lg px-4 py-2"
                                        :class="
                                            message.role === 'user'
                                                ? 'bg-primary text-primary-foreground'
                                                : 'bg-muted'
                                        "
                                    >
                                        <p class="text-sm whitespace-pre-wrap">
                                            {{ message.content }}
                                        </p>
                                    </div>
                                    <div
                                        class="flex items-center gap-2 text-xs text-muted-foreground"
                                    >
                                        <span>{{
                                            formatTime(message.created_at)
                                        }}</span>
                                        <span
                                            v-if="message.model"
                                            class="text-xs"
                                        >
                                            &bull; {{ message.model }}
                                        </span>
                                        <span
                                            v-if="message.response_time_ms"
                                            class="text-xs"
                                        >
                                            &bull;
                                            {{ message.response_time_ms }}ms
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
