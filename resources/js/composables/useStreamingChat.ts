import { ref } from 'vue';
import type { StreamChunk } from '@/types/chat';

export function useStreamingChat() {
    const isStreaming = ref(false);
    const streamingContent = ref('');
    const error = ref<string | null>(null);

    let eventSource: EventSource | null = null;

    function startStream(
        url: string,
        onChunk: (content: string) => void,
        onComplete: () => void,
        onError: (error: string) => void
    ) {
        // Close any existing connection
        stopStream();

        isStreaming.value = true;
        streamingContent.value = '';
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
        error,
        startStream,
        stopStream,
    };
}
