<script setup lang="ts">
import * as ChatbotAnalyticsController from '@/actions/App/Http/Controllers/ChatbotAnalyticsController';
import * as ChatbotController from '@/actions/App/Http/Controllers/ChatbotController';
import Heading from '@/components/Heading.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type {
    Chatbot,
    ChatbotStats,
    WidgetConversation,
} from '@/types/chatbot';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    BarChart3,
    Bot,
    Code,
    Eye,
    MessageSquare,
    Pencil,
    Star,
    Target,
    Trash2,
    Users,
} from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Props {
    chatbot: Chatbot;
    recentConversations: WidgetConversation[];
    stats: ChatbotStats;
}

const props = defineProps<Props>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: t('chatbots.index.heading'), href: ChatbotController.index().url },
    { title: props.chatbot.name, href: '#' },
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

const deleteChatbot = () => {
    router.delete(ChatbotController.destroy({ chatbot: props.chatbot.id }).url);
};

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
    <Head :title="chatbot.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-4xl">
                <div class="mb-8 flex items-start justify-between">
                    <div>
                        <div class="mb-2 flex items-center gap-3">
                            <Heading :title="chatbot.name" />
                            <Badge :variant="statusVariant(chatbot.status)">
                                {{ chatbot.status }}
                            </Badge>
                        </div>
                        <p
                            v-if="chatbot.description"
                            class="text-muted-foreground"
                        >
                            {{ chatbot.description }}
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <Button variant="outline" as-child>
                            <Link
                                :href="
                                    ChatbotController.preview({
                                        chatbot: chatbot.id,
                                    }).url
                                "
                            >
                                <Eye class="mr-2 h-4 w-4" />
                                {{ t('chatbots.show.preview') }}
                            </Link>
                        </Button>
                        <Button variant="outline" as-child>
                            <Link
                                :href="
                                    ChatbotAnalyticsController.show({
                                        chatbot: chatbot.id,
                                    }).url
                                "
                            >
                                <BarChart3 class="mr-2 h-4 w-4" />
                                {{ t('chatbots.show.analytics') }}
                            </Link>
                        </Button>
                        <Button as-child>
                            <Link
                                :href="
                                    ChatbotController.embed({
                                        chatbot: chatbot.id,
                                    }).url
                                "
                            >
                                <Code class="mr-2 h-4 w-4" />
                                {{ t('chatbots.show.embed') }}
                            </Link>
                        </Button>
                        <Button variant="outline" as-child>
                            <Link
                                :href="
                                    ChatbotController.edit({
                                        chatbot: chatbot.id,
                                    }).url
                                "
                            >
                                <Pencil class="mr-2 h-4 w-4" />
                                {{ t('common.edit') }}
                            </Link>
                        </Button>
                        <Dialog>
                            <DialogTrigger as-child>
                                <Button variant="destructive">
                                    <Trash2 class="mr-2 h-4 w-4" />
                                    {{ t('common.delete') }}
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>{{
                                        t('chatbots.show.delete_chatbot')
                                    }}</DialogTitle>
                                    <DialogDescription>
                                        {{ t('common.confirm_delete') }} "{{
                                            chatbot.name
                                        }}"?
                                        {{ t('chatbots.show.delete_warning') }}
                                        {{ t('common.action_irreversible') }}
                                    </DialogDescription>
                                </DialogHeader>
                                <DialogFooter>
                                    <DialogClose as-child>
                                        <Button variant="outline">{{
                                            t('common.cancel')
                                        }}</Button>
                                    </DialogClose>
                                    <Button
                                        variant="destructive"
                                        @click="deleteChatbot"
                                    >
                                        {{ t('common.delete') }}
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    </div>
                </div>

                <!-- Stats Cards -->
                <div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardHeader
                            class="flex flex-row items-center justify-between pb-2"
                        >
                            <CardTitle class="text-sm font-medium">
                                Conversations
                            </CardTitle>
                            <MessageSquare
                                class="h-4 w-4 text-muted-foreground"
                            />
                        </CardHeader>
                        <CardContent>
                            <div class="text-2xl font-bold">
                                {{ stats.total_conversations }}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader
                            class="flex flex-row items-center justify-between pb-2"
                        >
                            <CardTitle class="text-sm font-medium">
                                Sessions
                            </CardTitle>
                            <Users class="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div class="text-2xl font-bold">
                                {{ stats.total_sessions }}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader
                            class="flex flex-row items-center justify-between pb-2"
                        >
                            <CardTitle class="text-sm font-medium">
                                Avg Rating
                            </CardTitle>
                            <Star class="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div class="text-2xl font-bold">
                                {{
                                    stats.avg_rating
                                        ? stats.avg_rating.toFixed(1)
                                        : '-'
                                }}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader
                            class="flex flex-row items-center justify-between pb-2"
                        >
                            <CardTitle class="text-sm font-medium">
                                Resolution Rate
                            </CardTitle>
                            <Target class="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div class="text-2xl font-bold">
                                {{ stats.resolution_rate }}%
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <!-- Target Info -->
                <div class="mb-8 space-y-6">
                    <HeadingSmall
                        title="Agent or Agents Team"
                        description="The agent or team powering this chatbot"
                    />

                    <Card>
                        <CardHeader>
                            <div class="flex items-center gap-3">
                                <component
                                    :is="chatbot.agent ? Bot : Users"
                                    class="h-5 w-5 text-muted-foreground"
                                />
                                <div>
                                    <CardTitle class="text-base">
                                        {{
                                            chatbot.agent?.name ||
                                            chatbot.agent_team?.name
                                        }}
                                    </CardTitle>
                                    <CardDescription>
                                        {{
                                            chatbot.agent
                                                ? `${chatbot.agent.type} agent`
                                                : 'Agents Team'
                                        }}
                                    </CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                    </Card>
                </div>

                <!-- Recent Conversations -->
                <div class="space-y-6">
                    <div class="flex items-center justify-between">
                        <HeadingSmall
                            title="Recent Conversations"
                            description="Latest conversations from visitors"
                        />
                        <Button variant="outline" size="sm" as-child>
                            <Link
                                :href="
                                    ChatbotController.conversations({
                                        chatbot: chatbot.id,
                                    }).url
                                "
                            >
                                View All
                            </Link>
                        </Button>
                    </div>

                    <div
                        v-if="recentConversations.length === 0"
                        class="rounded-lg border border-dashed p-8 text-center"
                    >
                        <MessageSquare
                            class="mx-auto h-8 w-8 text-muted-foreground"
                        />
                        <p class="mt-2 text-sm text-muted-foreground">
                            No conversations yet. Embed the widget to start
                            receiving messages.
                        </p>
                    </div>

                    <div v-else class="space-y-3">
                        <Card
                            v-for="conversation in recentConversations"
                            :key="conversation.id"
                            class="cursor-pointer transition-colors hover:border-primary/50"
                        >
                            <Link
                                :href="
                                    ChatbotController.conversation({
                                        chatbot: chatbot.id,
                                        conversation: conversation.id,
                                    }).url
                                "
                            >
                                <CardHeader class="py-4">
                                    <div
                                        class="flex items-center justify-between"
                                    >
                                        <div class="flex items-center gap-3">
                                            <div
                                                class="flex h-8 w-8 items-center justify-center rounded-full bg-muted text-xs font-medium"
                                            >
                                                {{
                                                    conversation.session?.visitor_name?.[0]?.toUpperCase() ||
                                                    '?'
                                                }}
                                            </div>
                                            <div>
                                                <CardTitle class="text-sm">
                                                    {{
                                                        conversation.session
                                                            ?.visitor_name ||
                                                        conversation.session
                                                            ?.visitor_email ||
                                                        'Anonymous'
                                                    }}
                                                </CardTitle>
                                                <CardDescription
                                                    class="text-xs"
                                                >
                                                    {{
                                                        conversation.message_count
                                                    }}
                                                    messages
                                                </CardDescription>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <Badge
                                                v-if="conversation.is_resolved"
                                                variant="default"
                                            >
                                                Resolved
                                            </Badge>
                                            <Badge
                                                v-else-if="
                                                    conversation.is_abandoned
                                                "
                                                variant="secondary"
                                            >
                                                Abandoned
                                            </Badge>
                                            <span
                                                class="text-xs text-muted-foreground"
                                            >
                                                {{
                                                    formatDate(
                                                        conversation.created_at,
                                                    )
                                                }}
                                            </span>
                                        </div>
                                    </div>
                                </CardHeader>
                            </Link>
                        </Card>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
