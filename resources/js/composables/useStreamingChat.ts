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
    onStepComplete?: (step: number, response?: string) => void;
    onConsolidating?: () => void;
}

export function useStreamingChat() {
    const isStreaming = ref(false);
    const streamingContent = ref('');
    const toolCalls = ref<ToolCall[]>([]);
    const knowledgeBases = ref<KnowledgeBaseRef[]>([]);
    const executionPlan = ref<ExecutionStep[]>([]);
    const currentStep = ref<number | null>(null);
    const currentAgent = ref<'knowledge' | 'action' | 'direct' | null>(null);
    const isConsolidating = ref(false);
    const error = ref<string | null>(null);

    let abortController: AbortController | null = null;

    function startStream(
        url: string,
        onChunk: (content: string) => void,
        onComplete: () => void,
        onError: (error: string) => void,
        onToolCall?: (tool: ToolCall) => void,
        onKnowledgeBase?: (kb: KnowledgeBaseRef) => void,
        onExecutionPlan?: (steps: ExecutionStep[]) => void,
        onStepStart?: (step: number, agent: string, details: ExecutionStep) => void,
        onStepComplete?: (step: number, response?: string) => void,
        onConsolidating?: () => void
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
        isConsolidating.value = false;
        error.value = null;

        abortController = new AbortController();

        fetch(url, {
            signal: abortController.signal,
            credentials: 'same-origin',
            headers: { Accept: 'text/event-stream' },
        })
            .then(async (response) => {
                if (response.redirected) {
                    throw new Error(`Redirected to ${response.url} — session may have expired`);
                }

                if (!response.ok) {
                    const body = await response.text();
                    throw new Error(`HTTP ${response.status}: ${body.substring(0, 200)}`);
                }

                const contentType = response.headers.get('content-type') || '';
                if (!contentType.includes('text/event-stream')) {
                    const body = await response.text();
                    throw new Error(`Expected event-stream but got ${contentType}: ${body.substring(0, 200)}`);
                }

                const reader = response.body?.getReader();
                if (!reader) {
                    throw new Error('No response body');
                }

                const decoder = new TextDecoder();
                let buffer = '';

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;

                    buffer += decoder.decode(value, { stream: true });
                    const lines = buffer.split('\n');
                    buffer = lines.pop() || '';

                    for (const line of lines) {
                        if (!line.startsWith('data: ')) continue;
                        const payload = line.slice(6).trim();

                        if (payload === '[DONE]') {
                            stopStream();
                            onComplete();
                            return;
                        }

                        try {
                            const data: StreamChunk = JSON.parse(payload);

                            if (data.error) {
                                error.value = data.error;
                                stopStream();
                                onError(data.error);
                                return;
                            }

                            if (data.type === 'execution_plan' && data.steps) {
                                executionPlan.value = data.steps;
                                onExecutionPlan?.(data.steps);
                            } else if (data.type === 'step_start' && data.step !== undefined && data.agent && data.details) {
                                currentStep.value = data.step;
                                currentAgent.value = data.agent;
                                onStepStart?.(data.step, data.agent, data.details);
                            } else if (data.type === 'step_complete' && data.step !== undefined) {
                                onStepComplete?.(data.step, data.response);
                            } else if (data.type === 'consolidating') {
                                isConsolidating.value = true;
                                onConsolidating?.();
                            } else if (data.type === 'tool_call' && data.tool) {
                                const toolCall: ToolCall = { name: data.tool };
                                toolCalls.value.push(toolCall);
                                onToolCall?.(toolCall);
                            } else if (data.type === 'knowledge_base' && data.name) {
                                const kb: KnowledgeBaseRef = { name: data.name, id: data.id };
                                knowledgeBases.value.push(kb);
                                onKnowledgeBase?.(kb);
                            } else if (data.content) {
                                streamingContent.value += data.content;
                                onChunk(data.content);
                            }
                        } catch (e) {
                            console.error('Failed to parse SSE message:', e);
                        }
                    }
                }

                // Stream ended without [DONE]
                stopStream();
                onComplete();
            })
            .catch((err) => {
                if (err.name === 'AbortError') return;
                error.value = err.message || 'Connection lost. Please try again.';
                stopStream();
                onError(error.value);
            });
    }

    function stopStream() {
        if (abortController) {
            abortController.abort();
            abortController = null;
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
        isConsolidating,
        error,
        startStream,
        stopStream,
    };
}
