<script setup lang="ts">
import * as AgentTeamController from '@/actions/App/Http/Controllers/AgentTeamController';
import * as TeamStreamController from '@/actions/App/Http/Controllers/TeamStreamController';
import ChatInput from '@/components/chat/ChatInput.vue';
import ChatMessage from '@/components/chat/ChatMessage.vue';
import ExecutionPlanIndicator from '@/components/chat/ExecutionPlanIndicator.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useStreamingChat } from '@/composables/useStreamingChat';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { AgentTeam } from '@/types/agents';
import type { Conversation, Message, ToolCall, KnowledgeBaseRef, ExecutionStep } from '@/types/chat';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Bot, Brain, Plus, Users, Zap } from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';

interface Props {
    team: AgentTeam;
    conversation: Conversation;
}

const props = defineProps<Props>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Agent Teams', href: AgentTeamController.index().url },
    { title: props.team.name, href: AgentTeamController.show({ agent_team: props.team.id }).url },
    { title: 'Chat', href: '#' },
]);

// Chat state
const messagesContainer = ref<HTMLDivElement | null>(null);
const messages = ref<Message[]>([...props.conversation.messages]);
const streamingMessage = ref<string>('');
const activeToolCalls = ref<ToolCall[]>([]);
const activeKnowledgeBases = ref<KnowledgeBaseRef[]>([]);
const activeExecutionPlan = ref<ExecutionStep[]>([]);
const activeStep = ref<number | null>(null);
const completedSteps = ref<number[]>([]);
const consolidating = ref(false);
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

    router.post(AgentTeamController.newConversation({ agentTeam: props.team.id }).url);
}

// Send message
async function handleSendMessage(content: string) {
    error.value = null;
    streamingMessage.value = '';
    activeToolCalls.value = [];
    activeKnowledgeBases.value = [];
    activeExecutionPlan.value = [];
    activeStep.value = null;
    completedSteps.value = [];
    consolidating.value = false;

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
        AgentTeamController.sendMessage({ agentTeam: props.team.id }).url,
        { message: content },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                // Start streaming response
                const streamUrl = TeamStreamController.stream({
                    agentTeam: props.team.id,
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
                        activeToolCalls.value = [];
                        activeKnowledgeBases.value = [];
                        activeExecutionPlan.value = [];
                        activeStep.value = null;
                        completedSteps.value = [];
                        consolidating.value = false;
                    },
                    (err) => {
                        error.value = err;
                        streamingMessage.value = '';
                        activeToolCalls.value = [];
                        activeKnowledgeBases.value = [];
                        activeExecutionPlan.value = [];
                        activeStep.value = null;
                        completedSteps.value = [];
                        consolidating.value = false;
                    },
                    (toolCall) => {
                        activeToolCalls.value.push(toolCall);
                    },
                    (kb) => {
                        activeKnowledgeBases.value.push(kb);
                    },
                    (steps) => {
                        activeExecutionPlan.value = steps;
                    },
                    (step, _agent, _details) => {
                        activeStep.value = step;
                    },
                    (step) => {
                        completedSteps.value.push(step);
                    },
                    () => {
                        // Consolidating - mark all steps complete and show consolidation phase
                        completedSteps.value = activeExecutionPlan.value.map((_, i) => i);
                        activeStep.value = null;
                        consolidating.value = true;
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

// Get agent info for display
const triageAgent = computed(() => props.team.triage_agent);
const knowledgeAgent = computed(() => props.team.knowledge_agent);
const actionAgent = computed(() => props.team.action_agent);
const configuredAgentCount = computed(() => {
    return [triageAgent.value, knowledgeAgent.value, actionAgent.value].filter(Boolean).length;
});

// Get execution plan from message metadata
function getMessageExecutionPlan(message: Message): ExecutionStep[] | null {
    const metadata = message.metadata as { execution_plan?: ExecutionStep[] } | null;
    return metadata?.execution_plan ?? null;
}
</script>

<template>
    <Head :title="`Chat with ${team.name}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-[calc(100vh-4rem)] flex-col">
            <!-- Header -->
            <div class="border-b bg-background px-4 py-3">
                <div class="mx-auto flex max-w-4xl items-center gap-4">
                    <Button variant="ghost" size="icon" as-child>
                        <Link :href="AgentTeamController.show({ agent_team: team.id }).url">
                            <ArrowLeft class="h-4 w-4" />
                        </Link>
                    </Button>
                    <Users class="h-5 w-5 text-muted-foreground" />
                    <Heading :title="team.name" />
                    <div class="ml-auto flex items-center gap-2">
                        <!-- Agent badges -->
                        <div class="hidden sm:flex items-center gap-1">
                            <Badge
                                v-if="triageAgent"
                                variant="outline"
                                class="text-xs gap-1"
                            >
                                <Bot class="h-3 w-3" />
                                Triage
                            </Badge>
                            <Badge
                                v-if="knowledgeAgent"
                                variant="outline"
                                class="text-xs gap-1"
                            >
                                <Brain class="h-3 w-3" />
                                Knowledge
                            </Badge>
                            <Badge
                                v-if="actionAgent"
                                variant="outline"
                                class="text-xs gap-1"
                            >
                                <Zap class="h-3 w-3" />
                                Action
                            </Badge>
                        </div>
                        <Badge variant="secondary" class="sm:hidden">
                            {{ configuredAgentCount }} agents
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
                        <Users class="mb-4 h-12 w-12 text-muted-foreground" />
                        <p class="text-lg font-medium">Start a conversation</p>
                        <p class="text-sm text-muted-foreground">
                            Send a message to test {{ team.name }}
                        </p>
                        <p class="mt-2 text-xs text-muted-foreground">
                            Your message will be analyzed and routed to the appropriate agents
                        </p>
                    </div>

                    <!-- Messages -->
                    <template v-for="message in displayMessages" :key="message.id">
                        <!-- Execution plan indicator for streaming message -->
                        <div
                            v-if="message.id === 'streaming' && activeExecutionPlan.length > 0"
                            class="flex gap-3"
                        >
                            <div class="h-8 w-8 shrink-0" />
                            <ExecutionPlanIndicator
                                :steps="activeExecutionPlan"
                                :current-step="activeStep"
                                :completed-steps="completedSteps"
                                :is-processing="isStreaming"
                                :is-consolidating="consolidating"
                                collapsible
                            />
                        </div>

                        <!-- Execution plan indicator from saved message metadata -->
                        <div
                            v-else-if="message.role === 'assistant' && getMessageExecutionPlan(message)"
                            class="flex gap-3"
                        >
                            <div class="h-8 w-8 shrink-0" />
                            <ExecutionPlanIndicator
                                :steps="getMessageExecutionPlan(message)!"
                                :completed-steps="getMessageExecutionPlan(message)!.map((_, i) => i)"
                                collapsible
                            />
                        </div>

                        <ChatMessage
                            :message="message"
                            :is-streaming="message.id === 'streaming'"
                            :tool-calls="message.id === 'streaming' ? activeToolCalls : undefined"
                            :knowledge-bases="message.id === 'streaming' ? activeKnowledgeBases : undefined"
                        />
                    </template>

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
