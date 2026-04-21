<script setup lang="ts">
import * as AgentTeamController from '@/actions/App/Http/Controllers/AgentTeamController';
import * as TeamStreamController from '@/actions/App/Http/Controllers/TeamStreamController';
import ChatInput from '@/components/chat/ChatInput.vue';
import ChatMessage from '@/components/chat/ChatMessage.vue';
import ExecutionPlanIndicator from '@/components/chat/ExecutionPlanIndicator.vue';
import { useStreamingChat } from '@/composables/useStreamingChat';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import type { AgentTeam } from '@/types/agents';
import type {
    Conversation,
    ExecutionStep,
    KnowledgeBaseRef,
    Message,
    ToolCall,
} from '@/types/chat';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Bot, Brain, Plus, Users, Zap } from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Props {
    team: AgentTeam;
    conversation: Conversation;
}

const props = defineProps<Props>();

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

    router.post(
        AgentTeamController.newConversation({ agentTeam: props.team.id }).url,
    );
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
                        completedSteps.value = activeExecutionPlan.value.map(
                            (_, i) => i,
                        );
                        activeStep.value = null;
                        consolidating.value = true;
                    },
                );
            },
            onError: (errors) => {
                // Remove optimistic message on error
                messages.value = messages.value.filter(
                    (m) => m.id !== userMessage.id,
                );
                error.value = Object.values(errors).flat().join(', ');
            },
        },
    );
}

// Get agent info for display
const triageAgent = computed(() => props.team.triage_agent);
const knowledgeAgent = computed(() => props.team.knowledge_agent);
const actionAgent = computed(() => props.team.action_agent);
const configuredAgentCount = computed(() => {
    return [triageAgent.value, knowledgeAgent.value, actionAgent.value].filter(
        Boolean,
    ).length;
});

// Get execution plan from message metadata
function getMessageExecutionPlan(message: Message): ExecutionStep[] | null {
    const metadata = message.metadata as {
        execution_plan?: ExecutionStep[];
    } | null;
    return metadata?.execution_plan ?? null;
}
</script>

<template>
    <Head :title="`Chat with ${team.name}`" />

    <AppLayoutV2
        :title="t('app_v2.nav.agent_teams')"
        full-bleed
        force-collapsed-on-mount
    >
        <div class="flex min-h-0 flex-1 flex-col">
            <!-- Header strip — glass + soft border, matches flow toolbar. -->
            <div
                class="sp-glass flex h-14 shrink-0 items-center gap-3 border-b border-soft px-4"
            >
                <Link
                    :href="AgentTeamController.show({ agent_team: team.id }).url"
                    class="flex size-8 items-center justify-center rounded-xs text-ink-muted transition-colors hover:bg-white/5 hover:text-ink"
                >
                    <ArrowLeft class="size-4" />
                </Link>
                <Users class="size-4 text-ink-muted" />
                <h1 class="truncate text-sm font-semibold text-ink">
                    {{ team.name }}
                </h1>
                <div class="ml-auto flex items-center gap-2">
                    <!-- Agent chips (hidden on small screens; count pill shown instead). -->
                    <div class="hidden items-center gap-1 sm:flex">
                        <span
                            v-if="triageAgent"
                            class="inline-flex items-center gap-1 rounded-pill border border-soft bg-white/5 px-2 py-0.5 text-[10px] font-semibold tracking-wider text-ink-muted uppercase"
                        >
                            <Bot class="size-3" />
                            Triage
                        </span>
                        <span
                            v-if="knowledgeAgent"
                            class="inline-flex items-center gap-1 rounded-pill border border-soft bg-white/5 px-2 py-0.5 text-[10px] font-semibold tracking-wider text-ink-muted uppercase"
                        >
                            <Brain class="size-3" />
                            Knowledge
                        </span>
                        <span
                            v-if="actionAgent"
                            class="inline-flex items-center gap-1 rounded-pill border border-soft bg-white/5 px-2 py-0.5 text-[10px] font-semibold tracking-wider text-ink-muted uppercase"
                        >
                            <Zap class="size-3" />
                            Action
                        </span>
                    </div>
                    <span
                        class="inline-flex items-center rounded-pill border border-soft bg-white/5 px-2 py-0.5 text-[10px] font-semibold tracking-wider text-ink-muted uppercase sm:hidden"
                    >
                        {{ configuredAgentCount }} agents
                    </span>
                    <button
                        type="button"
                        :disabled="isStreaming"
                        class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3 py-1 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10 disabled:opacity-50"
                        @click="handleNewConversation"
                    >
                        <Plus class="size-3.5" />
                        New Chat
                    </button>
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
                        <div
                            class="flex size-12 items-center justify-center rounded-xs bg-white/5 text-ink-muted"
                        >
                            <Users class="size-5" />
                        </div>
                        <p class="mt-4 text-sm font-semibold text-ink">
                            Start a conversation
                        </p>
                        <p class="mt-1 text-xs text-ink-muted">
                            Send a message to test {{ team.name }}
                        </p>
                        <p class="mt-2 text-[11px] text-ink-subtle">
                            Your message will be analyzed and routed to the
                            appropriate agents
                        </p>
                    </div>

                    <!-- Messages -->
                    <template
                        v-for="message in displayMessages"
                        :key="message.id"
                    >
                        <!-- Execution plan indicator for streaming message -->
                        <div
                            v-if="
                                message.id === 'streaming' &&
                                activeExecutionPlan.length > 0
                            "
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
                            v-else-if="
                                message.role === 'assistant' &&
                                getMessageExecutionPlan(message)
                            "
                            class="flex gap-3"
                        >
                            <div class="h-8 w-8 shrink-0" />
                            <ExecutionPlanIndicator
                                :steps="getMessageExecutionPlan(message)!"
                                :completed-steps="
                                    getMessageExecutionPlan(message)!.map(
                                        (_, i) => i,
                                    )
                                "
                                collapsible
                            />
                        </div>

                        <ChatMessage
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
                    </template>

                    <!-- Error banner — semantic red on our dark chrome. -->
                    <div
                        v-if="error"
                        class="rounded-sp-sm border border-sp-danger/30 bg-sp-danger/10 px-4 py-3 text-sm text-sp-danger"
                    >
                        {{ error }}
                    </div>
                </div>
            </div>

            <!-- Input strip. -->
            <div
                class="sp-glass shrink-0 border-t border-soft px-4 py-4"
            >
                <div class="mx-auto max-w-4xl">
                    <ChatInput
                        :disabled="isStreaming"
                        :loading="isStreaming"
                        @submit="handleSendMessage"
                    />
                </div>
            </div>
        </div>
    </AppLayoutV2>
</template>
