<script setup lang="ts">
import * as AgentController from '@/actions/App/Http/Controllers/AgentController';
import * as ChatStreamController from '@/actions/App/Http/Controllers/ChatStreamController';
import ChatInput from '@/components/chat/ChatInput.vue';
import ChatMessage from '@/components/chat/ChatMessage.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useStreamingChat } from '@/composables/useStreamingChat';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { Agent, AgentType } from '@/types/agents';
import type { Conversation, Message } from '@/types/chat';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Bot, Brain, Plus, Zap } from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';

interface Props {
    agent: Agent;
    conversation: Conversation;
}

const props = defineProps<Props>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Agents', href: AgentController.index().url },
    { title: props.agent.name, href: AgentController.show({ agent: props.agent.id }).url },
    { title: 'Chat', href: '#' },
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

// Chat state
const messagesContainer = ref<HTMLDivElement | null>(null);
const messages = ref<Message[]>([...props.conversation.messages]);
const streamingMessage = ref<string>('');
const error = ref<string | null>(null);

const { isStreaming, startStream } = useStreamingChat();

// Watch for prop updates (after Inertia reload)
watch(
    () => props.conversation.messages,
    (newMessages) => {
        messages.value = [...newMessages];
    }
);

// Auto-scroll to bottom
function scrollToBottom() {
    nextTick(() => {
        if (messagesContainer.value) {
            messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
        }
    });
}

watch(
    () => [messages.value.length, streamingMessage.value],
    () => scrollToBottom()
);

// Display messages including streaming
const displayMessages = computed(() => {
    const result = [...messages.value];
    if (streamingMessage.value && isStreaming.value) {
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

// Send message
async function handleSendMessage(content: string) {
    error.value = null;
    streamingMessage.value = '';

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
    scrollToBottom();

    // Send to server
    router.post(
        AgentController.sendMessage({ agent: props.agent.id }).url,
        { message: content },
        {
            preserveScroll: true,
            onSuccess: () => {
                // Start streaming response
                const streamUrl = ChatStreamController.stream({
                    agent: props.agent.id,
                    conversation: props.conversation.id,
                }).url;

                startStream(
                    streamUrl,
                    (chunk) => {
                        streamingMessage.value += chunk;
                    },
                    () => {
                        // On complete, refresh to get the saved message
                        router.reload({ only: ['conversation'] });
                        streamingMessage.value = '';
                    },
                    (err) => {
                        error.value = err;
                        streamingMessage.value = '';
                    }
                );
            },
            onError: (errors) => {
                // Remove optimistic message on error
                messages.value = messages.value.filter((m) => m.id !== userMessage.id);
                error.value = Object.values(errors).flat().join(', ');
            },
        }
    );
}
</script>

<template>
    <Head :title="`Chat with ${agent.name}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-[calc(100vh-4rem)] flex-col">
            <!-- Header -->
            <div class="border-b bg-background px-4 py-3">
                <div class="mx-auto flex max-w-4xl items-center gap-4">
                    <Button variant="ghost" size="icon" as-child>
                        <Link :href="AgentController.show({ agent: agent.id }).url">
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
                    />

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
