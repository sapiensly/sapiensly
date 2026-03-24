<script setup lang="ts">
import { computed } from 'vue';
import { Bot, User } from 'lucide-vue-next';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import ToolCallIndicator from '@/components/chat/ToolCallIndicator.vue';
import type { Message, ToolCall, KnowledgeBaseRef } from '@/types/chat';
import { marked } from 'marked';

const props = defineProps<{
    message: Message | { role: 'assistant' | 'user'; content: string };
    isStreaming?: boolean;
    toolCalls?: ToolCall[];
    knowledgeBases?: KnowledgeBaseRef[];
}>();

const isUser = computed(() => props.message.role === 'user');

const formattedTime = computed(() => {
    if ('created_at' in props.message && props.message.created_at) {
        return new Date(props.message.created_at).toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
        });
    }
    return '';
});

// Extract tool calls from message metadata
const messageToolCalls = computed<ToolCall[]>(() => {
    if (props.toolCalls && props.toolCalls.length > 0) {
        return props.toolCalls;
    }
    if ('metadata' in props.message && props.message.metadata?.tool_calls) {
        return props.message.metadata.tool_calls as ToolCall[];
    }
    return [];
});

// Extract knowledge bases from message metadata
const messageKnowledgeBases = computed<KnowledgeBaseRef[]>(() => {
    if (props.knowledgeBases && props.knowledgeBases.length > 0) {
        return props.knowledgeBases;
    }
    if ('metadata' in props.message && props.message.metadata?.knowledge_bases) {
        return props.message.metadata.knowledge_bases as KnowledgeBaseRef[];
    }
    return [];
});

const hasIndicators = computed(
    () => messageToolCalls.value.length > 0 || messageKnowledgeBases.value.length > 0
);

// Configure marked for safe rendering
marked.setOptions({
    breaks: true,
    gfm: true,
});

// Parse markdown for assistant messages only
const renderedContent = computed(() => {
    if (isUser.value) {
        return props.message.content;
    }
    try {
        return marked.parse(props.message.content || '');
    } catch {
        return props.message.content;
    }
});
</script>

<template>
    <div class="space-y-2">
        <!-- Tool/KB indicator -->
        <div
            v-if="hasIndicators && !isUser"
            class="flex gap-3"
        >
            <div class="h-8 w-8 shrink-0" />
            <ToolCallIndicator
                :tool-calls="messageToolCalls"
                :knowledge-bases="messageKnowledgeBases"
                :is-executing="isStreaming"
            />
        </div>

        <!-- Message -->
        <div
            class="flex gap-3"
            :class="isUser ? 'flex-row-reverse' : 'flex-row'"
        >
            <Avatar class="h-8 w-8 shrink-0">
                <AvatarFallback
                    :class="isUser ? 'bg-primary text-primary-foreground' : 'bg-muted'"
                >
                    <User v-if="isUser" class="h-4 w-4" />
                    <Bot v-else class="h-4 w-4" />
                </AvatarFallback>
            </Avatar>

            <div
                class="max-w-[80%] rounded-lg px-4 py-2"
                :class="
                    isUser
                        ? 'bg-primary text-primary-foreground'
                        : 'bg-muted text-foreground'
                "
            >
                <!-- User messages: plain text -->
                <p
                    v-if="isUser"
                    class="whitespace-pre-wrap text-sm"
                >
                    {{ message.content }}
                </p>

                <!-- Assistant messages: markdown -->
                <div
                    v-else
                    class="prose prose-sm dark:prose-invert max-w-none prose-p:my-1 prose-pre:my-2 prose-pre:bg-background-100 prose-code:text-xs prose-code:before:content-none prose-code:after:content-none"
                    v-html="renderedContent"
                />

                <!-- Streaming cursor -->
                <span
                    v-if="isStreaming && !isUser"
                    class="inline-block h-4 w-1 animate-pulse bg-current"
                />

                <p
                    v-if="formattedTime"
                    class="mt-1 text-xs opacity-70"
                >
                    {{ formattedTime }}
                </p>
            </div>
        </div>
    </div>
</template>
