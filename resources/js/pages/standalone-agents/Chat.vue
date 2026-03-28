<script setup lang="ts">
import * as AgentController from '@/actions/App/Http/Controllers/AgentController';
import ChatInput from '@/components/chat/ChatInput.vue';
import ChatMessage from '@/components/chat/ChatMessage.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import echo from '@/echo';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { Agent, AgentType } from '@/types/agents';
import type {
    Conversation,
    KnowledgeBaseRef,
    Message,
    ToolCall,
} from '@/types/chat';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Bot, Brain, Plus, Zap } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Props {
    agent: Agent;
    conversation: Conversation;
}

const props = defineProps<Props>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: t('agents.show.agents'), href: AgentController.index().url },
    {
        title: props.agent.name,
        href: AgentController.show({ agent: props.agent.id }).url,
    },
    { title: t('agents.chat.title'), href: '#' },
]);

const agentIcon = (type: AgentType) => {
    switch (type) {
        case 'triage':
            return Bot;
        case 'knowledge':
            return Brain;
        case 'action':
            return Zap;
        default:
            return Bot;
    }
};

interface FlowMenuData {
    message: string;
    options: { id: string; label: string }[];
}

// Chat state
const messagesContainer = ref<HTMLDivElement | null>(null);
const messages = ref<Message[]>([...props.conversation.messages]);
const streamingMessage = ref<string>('');
const isStreaming = ref(false);
const activeToolCalls = ref<ToolCall[]>([]);
const activeKnowledgeBases = ref<KnowledgeBaseRef[]>([]);
const error = ref<string | null>(null);
const flowMenu = ref<FlowMenuData | null>(null);

// Echo channel
let channel: ReturnType<typeof echo.private> | null = null;
let streamingTimeout: ReturnType<typeof setTimeout> | null = null;

function subscribeToConversation() {
    if (channel) {
        channel.stopListening('.AgentStreamChunk');
        channel.stopListening('.AgentStreamComplete');
        channel.stopListening('.AgentStreamError');
        echo.leave(`conversation.${props.conversation.id}`);
    }

    channel = echo.private(`conversation.${props.conversation.id}`);

    channel.listen(
        '.AgentStreamChunk',
        (data: {
            content?: string;
            type?: string;
            tool?: string;
            name?: string;
            id?: string;
        }) => {
            // Clear safety timeout — we're receiving data
            if (streamingTimeout) {
                clearTimeout(streamingTimeout);
                streamingTimeout = null;
            }

            if (data.type === 'flow_menu' && data.options) {
                flowMenu.value = {
                    message: data.message ?? '',
                    options: data.options,
                };
                isStreaming.value = false;
                // Add menu message as assistant message
                if (data.message) {
                    messages.value.push({
                        id: 'flow-menu-' + Date.now(),
                        conversation_id: props.conversation.id,
                        role: 'assistant' as const,
                        content: data.message,
                        tokens_used: null,
                        model: null,
                        metadata: null,
                        created_at: new Date().toISOString(),
                        updated_at: new Date().toISOString(),
                    });
                }
                scrollToBottom();
                return;
            }

            if (data.type === 'flow_message' && data.content) {
                messages.value.push({
                    id: 'flow-msg-' + Date.now(),
                    conversation_id: props.conversation.id,
                    role: 'assistant' as const,
                    content: data.content,
                    tokens_used: null,
                    model: null,
                    metadata: null,
                    created_at: new Date().toISOString(),
                    updated_at: new Date().toISOString(),
                });
                scrollToBottom();
                return;
            }

            if (data.type === 'flow_end') {
                flowMenu.value = null;
                return;
            }

            if (data.type === 'tool_call' && data.tool) {
                activeToolCalls.value.push({ name: data.tool });
                return;
            }

            if (data.type === 'knowledge_base' && data.name) {
                activeKnowledgeBases.value.push({
                    name: data.name,
                    id: data.id,
                });
                return;
            }

            if (data.content) {
                if (!isStreaming.value) {
                    isStreaming.value = true;
                }
                streamingMessage.value += data.content;
            }
        },
    );

    channel.listen('.AgentStreamComplete', () => {
        if (streamingTimeout) {
            clearTimeout(streamingTimeout);
            streamingTimeout = null;
        }

        // Promote streaming message to a local message to avoid flash
        const hadStreamingContent = !!streamingMessage.value;
        if (hadStreamingContent) {
            messages.value.push({
                id: 'completed-' + Date.now(),
                conversation_id: props.conversation.id,
                role: 'assistant' as const,
                content: streamingMessage.value,
                tokens_used: null,
                model: null,
                metadata: null,
                created_at: new Date().toISOString(),
                updated_at: new Date().toISOString(),
            });
        }

        isStreaming.value = false;
        streamingMessage.value = '';
        activeToolCalls.value = [];
        activeKnowledgeBases.value = [];

        // Sync with server — if no streaming content was received,
        // this reload will show the response from the database
        router.reload({ only: ['conversation'], preserveScroll: true });
    });

    channel.listen('.AgentStreamError', (data: { error: string }) => {
        isStreaming.value = false;
        streamingMessage.value = '';
        activeToolCalls.value = [];
        activeKnowledgeBases.value = [];
        error.value = data.error;
    });
}

subscribeToConversation();

onBeforeUnmount(() => {
    if (channel) {
        echo.leave(`conversation.${props.conversation.id}`);
        channel = null;
    }
});

// Watch for prop updates (after Inertia reload)
watch(
    () => props.conversation.messages,
    (newMessages) => {
        messages.value = [...newMessages];
    },
);

// Auto-scroll to bottom
function scrollToBottom() {
    nextTick(() => {
        if (messagesContainer.value) {
            messagesContainer.value.scrollTop =
                messagesContainer.value.scrollHeight;
        }
    });
}

watch(
    () => [messages.value.length, streamingMessage.value],
    () => scrollToBottom(),
);

// Display messages including streaming
const displayMessages = computed(() => {
    const result = [...messages.value];
    if (streamingMessage.value) {
        result.push({
            id: 'streaming',
            conversation_id: props.conversation.id,
            role: 'assistant' as const,
            content: streamingMessage.value,
            tokens_used: null,
            model: null,
            metadata: null,
            created_at: new Date().toISOString(),
            updated_at: new Date().toISOString(),
        });
    }
    return result;
});

// Create new conversation
function handleNewConversation() {
    if (isStreaming.value) return;

    router.post(AgentController.newConversation({ agent: props.agent.id }).url);
}

// Handle flow menu option selection
function handleFlowOption(option: { id: string; label: string }) {
    flowMenu.value = null;
    handleSendMessage(option.label);
}

// Send message
async function handleSendMessage(content: string) {
    error.value = null;
    streamingMessage.value = '';
    activeToolCalls.value = [];
    activeKnowledgeBases.value = [];
    flowMenu.value = null;

    // Optimistically add user message
    const userMessage: Message = {
        id: 'temp-' + Date.now(),
        conversation_id: props.conversation.id,
        role: 'user',
        content,
        tokens_used: null,
        model: null,
        metadata: null,
        created_at: new Date().toISOString(),
        updated_at: new Date().toISOString(),
    };
    messages.value.push(userMessage);
    isStreaming.value = true;
    scrollToBottom();

    // Safety timeout: if no broadcast received within 30s, reload from server
    if (streamingTimeout) clearTimeout(streamingTimeout);
    streamingTimeout = setTimeout(() => {
        if (isStreaming.value && !streamingMessage.value) {
            isStreaming.value = false;
            router.reload({ only: ['conversation'], preserveScroll: true });
        }
    }, 30000);

    // Send to server — the job will be dispatched and stream via Echo
    router.post(
        AgentController.sendMessage({ agent: props.agent.id }).url,
        { message: content },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                // Re-subscribe to ensure channel is active after Inertia round-trip
                subscribeToConversation();
            },
            onError: (errors) => {
                messages.value = messages.value.filter(
                    (m) => m.id !== userMessage.id,
                );
                error.value = Object.values(errors).flat().join(', ');
                isStreaming.value = false;
            },
        },
    );
}
</script>

<template>
    <Head :title="`${t('agents.chat.title')} - ${agent.name}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-[calc(100vh-4rem)] flex-col">
            <!-- Header -->
            <div class="border-b bg-background px-4 py-3">
                <div class="mx-auto flex max-w-4xl items-center gap-4">
                    <Button variant="ghost" size="icon" as-child>
                        <Link
                            :href="
                                AgentController.show({ agent: agent.id }).url
                            "
                        >
                            <ArrowLeft class="h-4 w-4" />
                        </Link>
                    </Button>
                    <component
                        :is="agentIcon(agent.type)"
                        class="h-5 w-5 text-muted-foreground"
                    />
                    <Heading :title="agent.name" />
                    <div class="ml-auto flex items-center gap-2">
                        <Badge variant="secondary">
                            {{ agent.model }}
                        </Badge>
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="isStreaming"
                            @click="handleNewConversation"
                        >
                            <Plus class="mr-1 h-4 w-4" />
                            New Chat
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Messages -->
            <div
                ref="messagesContainer"
                class="flex-1 overflow-y-auto px-4 py-6"
            >
                <div class="mx-auto max-w-4xl space-y-4">
                    <!-- Empty state -->
                    <div
                        v-if="displayMessages.length === 0"
                        class="flex flex-col items-center justify-center py-12 text-center"
                    >
                        <component
                            :is="agentIcon(agent.type)"
                            class="mb-4 h-12 w-12 text-muted-foreground"
                        />
                        <p class="text-lg font-medium">Start a conversation</p>
                        <p class="text-sm text-muted-foreground">
                            Send a message to test {{ agent.name }}
                        </p>
                    </div>

                    <!-- Messages -->
                    <ChatMessage
                        v-for="message in displayMessages"
                        :key="message.id"
                        :message="message"
                        :is-streaming="message.id === 'streaming'"
                        :tool-calls="
                            message.id === 'streaming'
                                ? activeToolCalls
                                : undefined
                        "
                        :knowledge-bases="
                            message.id === 'streaming'
                                ? activeKnowledgeBases
                                : undefined
                        "
                    />

                    <!-- Flow menu -->
                    <div
                        v-if="flowMenu"
                        class="flex flex-wrap gap-2"
                    >
                        <Button
                            v-for="option in flowMenu.options"
                            :key="option.id"
                            variant="outline"
                            size="sm"
                            class="rounded-full"
                            @click="handleFlowOption(option)"
                        >
                            {{ option.label }}
                        </Button>
                    </div>

                    <!-- Error display -->
                    <Card v-if="error" class="border-destructive">
                        <CardContent class="py-3 text-sm text-destructive">
                            {{ error }}
                        </CardContent>
                    </Card>
                </div>
            </div>

            <!-- Input -->
            <div class="border-t bg-background px-4 py-4">
                <div class="mx-auto max-w-4xl">
                    <ChatInput
                        :disabled="isStreaming"
                        :loading="isStreaming"
                        @submit="handleSendMessage"
                    />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
