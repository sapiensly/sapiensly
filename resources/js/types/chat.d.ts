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
    agent_id: string;
    title: string | null;
    metadata: Record<string, unknown> | null;
    messages: Message[];
    created_at: string;
    updated_at: string;
}

export interface ToolCall {
    name: string;
    id?: string;
}

export interface KnowledgeBaseRef {
    name: string;
    id?: string;
}

export interface StreamChunk {
    type?: 'tool_call' | 'knowledge_base' | 'content';
    content?: string;
    tool?: string;
    name?: string;
    id?: string;
    error?: string;
}
