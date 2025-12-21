import type {
    ConversationData,
    Message,
    SessionData,
    StreamEvent,
    VisitorInfo,
    WidgetConfig,
} from './types';

/**
 * API client for the widget endpoints.
 */
export class ApiClient {
    private baseUrl: string;
    private token: string;
    private sessionToken: string | null = null;

    constructor(baseUrl: string, token: string) {
        this.baseUrl = baseUrl.replace(/\/$/, '');
        this.token = token;
    }

    /**
     * Set the session token for authenticated requests.
     */
    setSessionToken(sessionToken: string): void {
        this.sessionToken = sessionToken;
    }

    /**
     * Make an authenticated API request.
     */
    private async request<T>(
        method: string,
        endpoint: string,
        body?: Record<string, unknown>
    ): Promise<T> {
        const url = `${this.baseUrl}/api/widget/v1${endpoint}`;
        const headers: Record<string, string> = {
            'Content-Type': 'application/json',
            Authorization: `Bearer ${this.token}`,
        };

        if (this.sessionToken) {
            headers['X-Session-Token'] = this.sessionToken;
        }

        const response = await fetch(url, {
            method,
            headers,
            body: body ? JSON.stringify(body) : undefined,
        });

        if (!response.ok) {
            const error = await response.json().catch(() => ({ message: 'Request failed' }));
            throw new Error(error.message || `API error: ${response.status}`);
        }

        return response.json();
    }

    /**
     * Fetch chatbot configuration.
     */
    async getConfig(): Promise<WidgetConfig> {
        const url = `${this.baseUrl}/api/widget/v1/config/${this.token}`;
        const response = await fetch(url);

        if (!response.ok) {
            const error = await response.json().catch(() => ({ message: 'Failed to load config' }));
            throw new Error(error.message || `Config error: ${response.status}`);
        }

        return response.json();
    }

    /**
     * Create a new session.
     */
    async createSession(visitorInfo?: VisitorInfo): Promise<SessionData> {
        const response = await this.request<{
            session_id: string;
            session_token: string;
            created_at: string;
        }>('POST', '/sessions', {
            visitor_email: visitorInfo?.email,
            visitor_name: visitorInfo?.name,
            visitor_metadata: visitorInfo?.metadata,
            page_url: window.location.href,
        });

        this.sessionToken = response.session_token;

        return {
            session_id: response.session_id,
            session_token: response.session_token,
            visitor_email: visitorInfo?.email,
            visitor_name: visitorInfo?.name,
            created_at: response.created_at,
        };
    }

    /**
     * Update session with visitor info.
     */
    async updateSession(sessionId: string, visitorInfo: VisitorInfo): Promise<void> {
        await this.request('PATCH', `/sessions/${sessionId}`, {
            visitor_email: visitorInfo.email,
            visitor_name: visitorInfo.name,
            visitor_metadata: visitorInfo.metadata,
            page_url: window.location.href,
        });
    }

    /**
     * Create a new conversation.
     */
    async createConversation(
        sessionToken: string,
        initialMessage?: string
    ): Promise<ConversationData & { initial_message?: Message }> {
        return this.request('POST', '/conversations', {
            session_token: sessionToken,
            initial_message: initialMessage,
        });
    }

    /**
     * Get messages for a conversation.
     */
    async getMessages(conversationId: string): Promise<Message[]> {
        const response = await this.request<{ messages: Message[] }>(
            'GET',
            `/conversations/${conversationId}/messages`
        );
        return response.messages;
    }

    /**
     * Send a message.
     */
    async sendMessage(
        conversationId: string,
        content: string
    ): Promise<{ message_id: string; stream_url: string }> {
        return this.request('POST', `/conversations/${conversationId}/messages`, { content });
    }

    /**
     * Stream the AI response using Server-Sent Events.
     */
    streamResponse(
        conversationId: string,
        onEvent: (event: StreamEvent) => void,
        onError: (error: Error) => void,
        onComplete: () => void
    ): () => void {
        const url = `${this.baseUrl}/api/widget/v1/conversations/${conversationId}/stream`;

        const eventSource = new EventSource(url);
        let isComplete = false;

        // For authenticated SSE, we need to use fetch with ReadableStream instead
        // because EventSource doesn't support custom headers.
        // Let's use fetch-based SSE.

        eventSource.close();

        const controller = new AbortController();

        fetch(url, {
            headers: {
                Authorization: `Bearer ${this.token}`,
                Accept: 'text/event-stream',
            },
            signal: controller.signal,
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error(`Stream error: ${response.status}`);
                }

                const reader = response.body?.getReader();
                if (!reader) {
                    throw new Error('No response body');
                }

                const decoder = new TextDecoder();
                let buffer = '';

                while (true) {
                    const { done, value } = await reader.read();

                    if (done) {
                        if (!isComplete) {
                            isComplete = true;
                            onComplete();
                        }
                        break;
                    }

                    buffer += decoder.decode(value, { stream: true });
                    const lines = buffer.split('\n');
                    buffer = lines.pop() || '';

                    for (const line of lines) {
                        if (line.startsWith('data: ')) {
                            const data = line.slice(6);

                            if (data === '[DONE]') {
                                isComplete = true;
                                onComplete();
                                return;
                            }

                            try {
                                const event = JSON.parse(data) as StreamEvent;
                                onEvent(event);
                            } catch {
                                // Ignore parse errors
                            }
                        }
                    }
                }
            })
            .catch((error) => {
                if (error.name !== 'AbortError') {
                    onError(error);
                }
            });

        // Return abort function
        return () => {
            controller.abort();
        };
    }

    /**
     * Submit feedback for a conversation.
     */
    async submitFeedback(
        conversationId: string,
        rating: number,
        feedback?: string,
        isResolved?: boolean
    ): Promise<void> {
        await this.request('POST', `/conversations/${conversationId}/feedback`, {
            rating,
            feedback,
            is_resolved: isResolved,
        });
    }
}
