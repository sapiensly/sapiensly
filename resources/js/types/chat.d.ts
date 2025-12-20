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

// Execution plan step types
export interface ExecutionStep {
    agent: 'knowledge' | 'action' | 'direct';
    query?: string;
    task?: string;
    response?: string;
    urgency?: 'low' | 'medium' | 'high';
    context?: Record<string, unknown>;
}

export interface StreamChunk {
    type?: 'execution_plan' | 'step_start' | 'step_complete' | 'tool_call' | 'knowledge_base' | 'content';
    content?: string;
    tool?: string;
    name?: string;
    id?: string;
    error?: string;
    // Execution plan fields
    steps?: ExecutionStep[];
    step?: number;
    agent?: 'knowledge' | 'action' | 'direct';
    details?: ExecutionStep;
}

// Legacy alias for backwards compatibility
export type RoutingDecision = ExecutionStep;

export interface TeamMessage extends Message {
    metadata?: {
        execution_plan?: ExecutionStep[];
        tool_calls?: ToolCall[];
        knowledge_bases?: KnowledgeBaseRef[];
    } | null;
}
