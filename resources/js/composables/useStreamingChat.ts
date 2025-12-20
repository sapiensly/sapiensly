import { ref } from 'vue';
import type { StreamChunk, ToolCall, KnowledgeBaseRef } from '@/types/chat';

export interface StreamCallbacks {
    onChunk: (content: string) => void;
    onComplete: () => void;
    onError: (error: string) => void;
    onToolCall?: (tool: ToolCall) => void;
    onKnowledgeBase?: (kb: KnowledgeBaseRef) => void;
}

export function useStreamingChat() {
    const isStreaming = ref(false);
    const streamingContent = ref('');
    const toolCalls = ref<ToolCall[]>([]);
    const knowledgeBases = ref<KnowledgeBaseRef[]>([]);
    const error = ref<string | null>(null);

    let eventSource: EventSource | null = null;

    function startStream(
        url: string,
        onChunk: (content: string) => void,
        onComplete: () => void,
        onError: (error: string) => void,
        onToolCall?: (tool: ToolCall) => void,
        onKnowledgeBase?: (kb: KnowledgeBaseRef) => void
    ) {
        // Close any existing connection
        stopStream();

        isStreaming.value = true;
        streamingContent.value = '';
        toolCalls.value = [];
        knowledgeBases.value = [];
        error.value = null;

        eventSource = new EventSource(url);

        eventSource.onmessage = (event) => {
            if (event.data === '[DONE]') {
                stopStream();
                onComplete();
                return;
            }

            try {
                const data: StreamChunk = JSON.parse(event.data);

                if (data.error) {
                    error.value = data.error;
                    stopStream();
                    onError(data.error);
                    return;
                }

                // Handle tool call events
                if (data.type === 'tool_call' && data.tool) {
                    const toolCall: ToolCall = { name: data.tool };
                    toolCalls.value.push(toolCall);
                    onToolCall?.(toolCall);
                    return;
                }

                // Handle knowledge base events
                if (data.type === 'knowledge_base' && data.name) {
                    const kb: KnowledgeBaseRef = { name: data.name, id: data.id };
                    knowledgeBases.value.push(kb);
                    onKnowledgeBase?.(kb);
                    return;
                }

                // Handle content
                if (data.content) {
                    streamingContent.value += data.content;
                    onChunk(data.content);
                }
            } catch (e) {
                console.error('Failed to parse SSE message:', e);
            }
        };

        eventSource.onerror = () => {
            error.value = 'Connection lost. Please try again.';
            stopStream();
            onError(error.value);
        };
    }

    function stopStream() {
        if (eventSource) {
            eventSource.close();
            eventSource = null;
        }
        isStreaming.value = false;
    }

    return {
        isStreaming,
        streamingContent,
        toolCalls,
        knowledgeBases,
        error,
        startStream,
        stopStream,
    };
}
