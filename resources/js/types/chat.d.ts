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
    type?: 'tool_call' | 'knowledge_base' | 'content' | 'routing' | 'agent_start';
    content?: string;
    tool?: string;
    name?: string;
    id?: string;
    error?: string;
    // Routing-specific fields (for team orchestration)
    agent?: 'triage' | 'knowledge' | 'action';
    decision?: RoutingDecision;
}

export interface RoutingDecision {
    action: 'knowledge' | 'action' | 'direct';
    query?: string;
    task?: string;
    response?: string;
    urgency?: string;
    context?: Record<string, unknown>;
}

export interface TeamMessage extends Message {
    routing?: RoutingDecision;
}
