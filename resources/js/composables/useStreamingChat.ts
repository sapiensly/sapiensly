import { ref } from 'vue';
import type { StreamChunk, ToolCall, KnowledgeBaseRef, ExecutionStep } from '@/types/chat';

export interface StreamCallbacks {
    onChunk: (content: string) => void;
    onComplete: () => void;
    onError: (error: string) => void;
    onToolCall?: (tool: ToolCall) => void;
    onKnowledgeBase?: (kb: KnowledgeBaseRef) => void;
    onExecutionPlan?: (steps: ExecutionStep[]) => void;
    onStepStart?: (step: number, agent: string, details: ExecutionStep) => void;
    onStepComplete?: (step: number) => void;
}

export function useStreamingChat() {
    const isStreaming = ref(false);
    const streamingContent = ref('');
    const toolCalls = ref<ToolCall[]>([]);
    const knowledgeBases = ref<KnowledgeBaseRef[]>([]);
    const executionPlan = ref<ExecutionStep[]>([]);
    const currentStep = ref<number | null>(null);
    const currentAgent = ref<'knowledge' | 'action' | 'direct' | null>(null);
    const error = ref<string | null>(null);

    let eventSource: EventSource | null = null;

    function startStream(
        url: string,
        onChunk: (content: string) => void,
        onComplete: () => void,
        onError: (error: string) => void,
        onToolCall?: (tool: ToolCall) => void,
        onKnowledgeBase?: (kb: KnowledgeBaseRef) => void,
        onExecutionPlan?: (steps: ExecutionStep[]) => void,
        onStepStart?: (step: number, agent: string, details: ExecutionStep) => void,
        onStepComplete?: (step: number) => void
    ) {
        // Close any existing connection
        stopStream();

        isStreaming.value = true;
        streamingContent.value = '';
        toolCalls.value = [];
        knowledgeBases.value = [];
        executionPlan.value = [];
        currentStep.value = null;
        currentAgent.value = null;
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

                // Handle execution plan event
                if (data.type === 'execution_plan' && data.steps) {
                    executionPlan.value = data.steps;
                    onExecutionPlan?.(data.steps);
                    return;
                }

                // Handle step start event
                if (data.type === 'step_start' && data.step !== undefined && data.agent && data.details) {
                    currentStep.value = data.step;
                    currentAgent.value = data.agent;
                    onStepStart?.(data.step, data.agent, data.details);
                    return;
                }

                // Handle step complete event
                if (data.type === 'step_complete' && data.step !== undefined) {
                    onStepComplete?.(data.step);
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
        executionPlan,
        currentStep,
        currentAgent,
        error,
        startStream,
        stopStream,
    };
}
