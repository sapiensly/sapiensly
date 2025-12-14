export type MessageRole = 'user' | 'assistant' | 'system';

export interface Message {
    id: string;
    conversation_id: string;
    role: MessageRole;
    content: string;
    tokens_used: number | null;
    model: string | null;
    metadata: Record<string, unknown> | null;
    created_at: string;
    updated_at: string;
}

export interface Conversation {
    id: string;
    user_id: number;
    agent_id: number;
    title: string | null;
    metadata: Record<string, unknown> | null;
    messages: Message[];
    created_at: string;
    updated_at: string;
}

export interface StreamChunk {
    content?: string;
    error?: string;
}
