<script setup lang="ts">
import * as ChatbotController from '@/actions/App/Http/Controllers/ChatbotController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { Chatbot } from '@/types/chatbot';
import { usePreviewChat, type PreviewMessage } from '@/composables/usePreviewChat';
import { Head, Link } from '@inertiajs/vue3';
import {
    AlertCircle,
    Book,
    Loader2,
    MessageSquare,
    RefreshCw,
    Send,
    Wrench,
    X,
} from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';
import { marked } from 'marked';

// Configure marked for safe rendering
marked.setOptions({
    breaks: true,
    gfm: true,
});

// Render markdown for assistant messages
function renderMarkdown(msg: PreviewMessage): string {
    if (msg.role === 'user') {
        return msg.content;
    }
    try {
        return marked.parse(msg.content || '') as string;
    } catch {
        return msg.content;
    }
}

interface Props {
    chatbot: Chatbot;
}

const props = defineProps<Props>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Chatbots', href: ChatbotController.index().url },
    {
        title: props.chatbot.name,
        href: ChatbotController.show({ chatbot: props.chatbot.id }).url,
    },
    { title: 'Preview', href: '#' },
]);

const isOpen = ref(true);
const message = ref('');
const messagesContainer = ref<HTMLElement | null>(null);

const {
    messages,
    isLoading,
    isStreaming,
    error,
    toolCalls,
    knowledgeBases,
    sendMessage: sendPreviewMessage,
    clearConversation,
} = usePreviewChat(props.chatbot.id);

const config = computed(() => props.chatbot.config);

const hasTarget = computed(
    () => props.chatbot.agent_id || props.chatbot.agent_team_id
);

const sendMessage = async () => {
    if (!message.value.trim() || isLoading.value || isStreaming.value) return;

    const content = message.value;
    message.value = '';
    await sendPreviewMessage(content);
};

const handleClear = async () => {
    await clearConversation();
};

// Auto-scroll to bottom when messages change
watch(
    messages,
    () => {
        nextTick(() => {
            if (messagesContainer.value) {
                messagesContainer.value.scrollTop =
                    messagesContainer.value.scrollHeight;
            }
        });
    },
    { deep: true }
);
</script>

<template>
    <Head title="Preview Chatbot" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-4xl">
                <div class="mb-8 flex items-center justify-between">
                    <Heading
                        title="Preview Widget"
                        :description="`Test ${chatbot.name} with real AI responses`"
                    />
                    <div class="flex gap-2">
                        <Button variant="outline" as-child>
                            <Link
                                :href="
                                    ChatbotController.edit({ chatbot: chatbot.id }).url
                                "
                            >
                                Edit Settings
                            </Link>
                        </Button>
                        <Button as-child>
                            <Link
                                :href="
                                    ChatbotController.embed({ chatbot: chatbot.id }).url
                                "
                            >
                                Get Embed Code
                            </Link>
                        </Button>
                    </div>
                </div>

                <!-- No agent/team warning -->
                <div
                    v-if="!hasTarget"
                    class="mb-6 flex items-start gap-3 rounded-lg border border-yellow-200 bg-yellow-50 p-4 dark:border-yellow-900 dark:bg-yellow-950"
                >
                    <AlertCircle class="h-5 w-5 text-yellow-600 dark:text-yellow-400" />
                    <div>
                        <p class="font-medium text-yellow-800 dark:text-yellow-200">
                            No Agent or Team configured
                        </p>
                        <p class="mt-1 text-sm text-yellow-700 dark:text-yellow-300">
                            This chatbot doesn't have an agent or team assigned yet.
                            <Link
                                :href="
                                    ChatbotController.edit({ chatbot: chatbot.id }).url
                                "
                                class="underline"
                            >
                                Edit the chatbot
                            </Link>
                            to add one.
                        </p>
                    </div>
                </div>

                <!-- Preview Container -->
                <div
                    class="relative min-h-[600px] rounded-lg border bg-gradient-to-br from-gray-100 to-gray-200 p-8 dark:from-gray-800 dark:to-gray-900"
                >
                    <!-- Simulated Website Content -->
                    <div class="space-y-4">
                        <div class="h-12 w-48 rounded bg-gray-300 dark:bg-gray-700" />
                        <div class="h-4 w-full rounded bg-gray-300 dark:bg-gray-700" />
                        <div class="h-4 w-3/4 rounded bg-gray-300 dark:bg-gray-700" />
                        <div class="h-4 w-5/6 rounded bg-gray-300 dark:bg-gray-700" />
                        <div class="h-32 w-full rounded bg-gray-300 dark:bg-gray-700" />
                        <div class="h-4 w-full rounded bg-gray-300 dark:bg-gray-700" />
                        <div class="h-4 w-2/3 rounded bg-gray-300 dark:bg-gray-700" />
                    </div>

                    <!-- Widget Preview -->
                    <div
                        class="absolute bottom-4"
                        :class="
                            config.appearance.position === 'bottom-right'
                                ? 'right-4'
                                : 'left-4'
                        "
                    >
                        <!-- Chat Bubble -->
                        <button
                            v-if="!isOpen"
                            class="flex h-14 w-14 items-center justify-center rounded-full shadow-lg transition-transform hover:scale-105"
                            :style="{ backgroundColor: config.appearance.primary_color }"
                            @click="isOpen = true"
                        >
                            <MessageSquare class="h-6 w-6 text-white" />
                        </button>

                        <!-- Chat Window -->
                        <div
                            v-else
                            class="flex h-[480px] w-[360px] flex-col overflow-hidden rounded-xl shadow-2xl"
                            :style="{ backgroundColor: config.appearance.background_color }"
                        >
                            <!-- Header -->
                            <div
                                class="flex items-center justify-between px-4 py-3"
                                :style="{ backgroundColor: config.appearance.primary_color }"
                            >
                                <span class="font-semibold text-white">
                                    {{ config.appearance.widget_title }}
                                </span>
                                <div class="flex items-center gap-2">
                                    <button
                                        class="text-white/80 hover:text-white"
                                        title="Clear conversation"
                                        @click="handleClear"
                                    >
                                        <RefreshCw class="h-4 w-4" />
                                    </button>
                                    <button
                                        class="text-white/80 hover:text-white"
                                        @click="isOpen = false"
                                    >
                                        <X class="h-5 w-5" />
                                    </button>
                                </div>
                            </div>

                            <!-- Tool/KB Indicators -->
                            <div
                                v-if="toolCalls.length > 0 || knowledgeBases.length > 0"
                                class="flex flex-wrap gap-1 border-b px-3 py-2"
                            >
                                <Badge
                                    v-for="tool in toolCalls"
                                    :key="tool.name"
                                    variant="secondary"
                                    class="text-xs"
                                >
                                    <Wrench class="mr-1 h-3 w-3" />
                                    {{ tool.name }}
                                </Badge>
                                <Badge
                                    v-for="kb in knowledgeBases"
                                    :key="kb.name"
                                    variant="outline"
                                    class="text-xs"
                                >
                                    <Book class="mr-1 h-3 w-3" />
                                    {{ kb.name }}
                                </Badge>
                            </div>

                            <!-- Error Display -->
                            <div
                                v-if="error"
                                class="border-b border-red-200 bg-red-50 px-3 py-2 text-sm text-red-600 dark:border-red-900 dark:bg-red-950 dark:text-red-400"
                            >
                                {{ error }}
                            </div>

                            <!-- Messages -->
                            <div
                                ref="messagesContainer"
                                class="flex-1 overflow-y-auto p-4"
                                :style="{ color: config.appearance.text_color }"
                            >
                                <!-- Welcome Message -->
                                <div
                                    v-if="messages.length === 0"
                                    class="mb-4 rounded-lg p-3"
                                    :style="{
                                        backgroundColor:
                                            config.appearance.primary_color + '20',
                                    }"
                                >
                                    {{ config.appearance.welcome_message }}
                                </div>

                                <!-- Message List -->
                                <div class="space-y-3">
                                    <div
                                        v-for="(msg, index) in messages"
                                        :key="msg.id || index"
                                        class="flex"
                                        :class="
                                            msg.role === 'user'
                                                ? 'justify-end'
                                                : 'justify-start'
                                        "
                                    >
                                        <div
                                            class="max-w-[80%] rounded-lg px-3 py-2"
                                            :class="msg.role === 'assistant' ? 'prose prose-sm max-w-none prose-p:my-1 prose-pre:my-2 prose-pre:bg-black/10 prose-code:text-xs prose-code:before:content-none prose-code:after:content-none' : 'whitespace-pre-wrap'"
                                            :style="{
                                                backgroundColor:
                                                    msg.role === 'user'
                                                        ? config.appearance.primary_color
                                                        : config.appearance.primary_color +
                                                          '20',
                                                color:
                                                    msg.role === 'user'
                                                        ? '#ffffff'
                                                        : config.appearance.text_color,
                                            }"
                                        >
                                            <template v-if="msg.isStreaming && !msg.content">
                                                <Loader2 class="h-4 w-4 animate-spin" />
                                            </template>
                                            <template v-else>
                                                <!-- User messages: plain text -->
                                                <span v-if="msg.role === 'user'">{{ msg.content }}</span>
                                                <!-- Assistant messages: markdown -->
                                                <div
                                                    v-else
                                                    v-html="renderMarkdown(msg)"
                                                />
                                                <span
                                                    v-if="msg.isStreaming"
                                                    class="ml-1 inline-block h-3 w-1 animate-pulse bg-current"
                                                />
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Input -->
                            <div class="border-t p-3">
                                <form class="flex gap-2" @submit.prevent="sendMessage">
                                    <input
                                        v-model="message"
                                        type="text"
                                        :placeholder="config.appearance.placeholder_text"
                                        :disabled="
                                            isLoading || isStreaming || !hasTarget
                                        "
                                        class="flex-1 rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-2 disabled:opacity-50"
                                        :style="{
                                            color: config.appearance.text_color,
                                            '--tw-ring-color':
                                                config.appearance.primary_color,
                                        }"
                                    />
                                    <button
                                        type="submit"
                                        :disabled="
                                            isLoading ||
                                            isStreaming ||
                                            !message.trim() ||
                                            !hasTarget
                                        "
                                        class="flex h-10 w-10 items-center justify-center rounded-lg text-white disabled:opacity-50"
                                        :style="{
                                            backgroundColor:
                                                config.appearance.primary_color,
                                        }"
                                    >
                                        <Loader2
                                            v-if="isLoading || isStreaming"
                                            class="h-4 w-4 animate-spin"
                                        />
                                        <Send v-else class="h-4 w-4" />
                                    </button>
                                </form>
                            </div>

                            <!-- Powered By -->
                            <div
                                v-if="config.behavior.show_powered_by"
                                class="border-t py-2 text-center text-xs text-muted-foreground"
                            >
                                Powered by Sapiensly
                            </div>
                        </div>
                    </div>
                </div>

                <p class="mt-4 text-center text-sm text-muted-foreground">
                    This preview uses real AI responses from your
                    {{ chatbot.agent ? 'agent' : 'team' }}. Messages are saved for
                    testing purposes.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
