import { ref, onMounted, onUnmounted } from 'vue';
import axios from 'axios';
import * as ChatbotPreviewController from '@/actions/App/Http/Controllers/ChatbotPreviewController';

export interface PreviewMessage {
    id?: string;
    role: 'user' | 'assistant';
    content: string;
    isStreaming?: boolean;
}

export interface ToolCall {
    name: string;
}

export interface KnowledgeBaseRef {
    name: string;
    id?: string;
}

export function usePreviewChat(chatbotId: string) {
    const conversationId = ref<string | null>(null);
    const messages = ref<PreviewMessage[]>([]);
    const isLoading = ref(false);
    const isStreaming = ref(false);
    const error = ref<string | null>(null);
    const toolCalls = ref<ToolCall[]>([]);
    const knowledgeBases = ref<KnowledgeBaseRef[]>([]);

    let eventSource: EventSource | null = null;

    async function init() {
        try {
            isLoading.value = true;
            error.value = null;

            const response = await axios.post(
                ChatbotPreviewController.init.url({ chatbot: chatbotId })
            );

            conversationId.value = response.data.conversation_id;
            messages.value = (response.data.messages || []).map(
                (msg: { id: string; role: string; content: string }) => ({
                    id: msg.id,
                    role: msg.role as 'user' | 'assistant',
                    content: msg.content,
                })
            );
        } catch (e: unknown) {
            const axiosError = e as { response?: { data?: { message?: string } } };
            error.value =
                axiosError.response?.data?.message ||
                'Failed to initialize preview chat';
        } finally {
            isLoading.value = false;
        }
    }

    async function sendMessage(content: string) {
        if (!conversationId.value || !content.trim()) return;

        try {
            isLoading.value = true;
            error.value = null;
            toolCalls.value = [];
            knowledgeBases.value = [];

            // Add user message immediately
            const userMessage: PreviewMessage = {
                role: 'user',
                content: content.trim(),
            };
            messages.value.push(userMessage);

            // Send to backend
            const response = await axios.post(
                ChatbotPreviewController.send.url({ chatbot: chatbotId }),
                {
                    conversation_id: conversationId.value,
                    content: content.trim(),
                }
            );

            userMessage.id = response.data.message_id;

            // Add placeholder for assistant response
            const assistantMessage: PreviewMessage = {
                role: 'assistant',
                content: '',
                isStreaming: true,
            };
            messages.value.push(assistantMessage);

            // Get the index of the assistant message for reactive updates
            const assistantIndex = messages.value.length - 1;

            // Start streaming response
            await startStream(assistantIndex);
        } catch (e: unknown) {
            const axiosError = e as { response?: { data?: { error?: string } } };
            const errorMsg =
                axiosError.response?.data?.error || 'Failed to send message';
            error.value = errorMsg;

            // If the error was in sending (no assistant message added yet),
            // remove the user message. Otherwise, the stream failed but
            // user message was saved, so keep it.
            const lastMessage = messages.value[messages.value.length - 1];
            if (lastMessage?.role === 'user') {
                messages.value.pop();
            } else if (lastMessage?.role === 'assistant' && !lastMessage.content) {
                // Remove empty assistant placeholder if stream failed
                messages.value.pop();
            }
        } finally {
            isLoading.value = false;
        }
    }

    function startStream(messageIndex: number) {
        return new Promise<void>((resolve, reject) => {
            if (!conversationId.value) {
                reject(new Error('No conversation'));
                return;
            }

            // Close any existing connection
            stopStream();

            isStreaming.value = true;

            const streamUrl = ChatbotPreviewController.stream.url({
                chatbot: chatbotId,
                conversation: conversationId.value,
            });

            eventSource = new EventSource(streamUrl);

            eventSource.onmessage = (event) => {
                if (event.data === '[DONE]') {
                    if (messages.value[messageIndex]) {
                        messages.value[messageIndex].isStreaming = false;
                    }
                    stopStream();
                    resolve();
                    return;
                }

                try {
                    const data = JSON.parse(event.data);

                    if (data.error) {
                        error.value = data.error;
                        if (messages.value[messageIndex]) {
                            messages.value[messageIndex].isStreaming = false;
                            messages.value[messageIndex].content =
                                messages.value[messageIndex].content || 'An error occurred.';
                        }
                        stopStream();
                        reject(new Error(data.error));
                        return;
                    }

                    // Handle tool call events
                    if (data.type === 'tool_call' && data.tool) {
                        toolCalls.value.push({ name: data.tool });
                        return;
                    }

                    // Handle knowledge base events
                    if (data.type === 'knowledge_base' && data.name) {
                        knowledgeBases.value.push({
                            name: data.name,
                            id: data.id,
                        });
                        return;
                    }

                    // Handle done event
                    if (data.type === 'done') {
                        if (messages.value[messageIndex]) {
                            messages.value[messageIndex].isStreaming = false;
                        }
                        stopStream();
                        resolve();
                        return;
                    }

                    // Handle content - update via array index for reactivity
                    if (data.content && messages.value[messageIndex]) {
                        messages.value[messageIndex].content += data.content;
                    }
                } catch (e) {
                    console.error('Failed to parse SSE message:', e);
                }
            };

            eventSource.onerror = () => {
                error.value = 'Connection lost. Please try again.';
                if (messages.value[messageIndex]) {
                    messages.value[messageIndex].isStreaming = false;
                }
                stopStream();
                reject(new Error('Connection lost'));
            };
        });
    }

    function stopStream() {
        if (eventSource) {
            eventSource.close();
            eventSource = null;
        }
        isStreaming.value = false;
    }

    async function clearConversation() {
        if (!conversationId.value) return;

        try {
            isLoading.value = true;
            error.value = null;

            const response = await axios.post(
                ChatbotPreviewController.clear.url({ chatbot: chatbotId }),
                { conversation_id: conversationId.value }
            );

            conversationId.value = response.data.conversation_id;
            messages.value = [];
            toolCalls.value = [];
            knowledgeBases.value = [];
        } catch (e: unknown) {
            const axiosError = e as { response?: { data?: { error?: string } } };
            error.value =
                axiosError.response?.data?.error || 'Failed to clear conversation';
        } finally {
            isLoading.value = false;
        }
    }

    onMounted(() => {
        init();
    });

    onUnmounted(() => {
        stopStream();
    });

    return {
        conversationId,
        messages,
        isLoading,
        isStreaming,
        error,
        toolCalls,
        knowledgeBases,
        sendMessage,
        clearConversation,
        init,
    };
}
