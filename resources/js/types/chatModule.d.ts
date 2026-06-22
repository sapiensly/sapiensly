export interface ChatModelOption {
    value: string;
    label: string;
    provider: string;
    // 'byok' = served by the tenant's own API key; 'system' = global key.
    source?: 'byok' | 'system';
}

export interface ChatAttachmentDto {
    id: string;
    original_name: string;
    mime: string;
    size_bytes: number;
    url: string;
}

export type ChatMessageStatus = 'pending' | 'streaming' | 'complete' | 'error';

export type ChatMessageType = 'text' | 'action_proposal' | 'action_result';

export interface ChatAgentRef {
    id: string;
    name: string;
}

// The synthesized, executable close of a multi-agent thread (ActionCard).
export interface ActionPayloadDto {
    action_type: string;
    action_label: string;
    // Plain-language answer to the user's question (the headline they read first).
    // Optional: older proposals predate this field.
    summary?: string;
    agreed_by: string[];
    parameters: Record<string, unknown>;
    rationale: string;
    executable?: boolean;
}

export interface ChatMessageDto {
    id: string;
    role: 'user' | 'assistant' | 'system';
    content: string | null;
    model: string | null;
    status: ChatMessageStatus;
    error: string | null;
    created_at: string | null;
    attachments: ChatAttachmentDto[];
    // Multi-agent (@mention) fields. agent set => agent-authored bubble.
    agent_id?: string | null;
    agent?: ChatAgentRef | null;
    message_type?: ChatMessageType;
    agent_data_context?: Record<string, string> | null;
    action_payload?: ActionPayloadDto | null;
    // Agents this turn consulted (the "ask another agent" feature).
    consultation_context?: ConsultationDto[] | null;
}

export interface ConsultationDto {
    id: string;
    agent_id: string;
    agent_name: string;
    question: string;
    answer: string | null;
    visible: boolean;
    // Client-only: true while awaiting the consulted agent's answer (live).
    pending?: boolean;
}

export type ChatSynthesisStatus =
    | null
    | 'pending'
    | 'ready'
    | 'executed'
    | 'dismissed';

export interface ChatListItem {
    id: string;
    title: string | null;
    chat_project_id: string | null;
    last_message_at: string | null;
}

export interface ChatProjectDto {
    id: string;
    name: string;
    description: string | null;
    custom_instructions: string | null;
    knowledge_base_ids: string[];
}

export interface KnowledgeBaseOption {
    id: string;
    name: string;
    status: string;
    document_count: number;
}

export interface ChatToolOption {
    id: string;
    name: string;
    type: string;
}

export interface ChatAgentOption {
    id: string;
    name: string;
    type: string;
    web_search: boolean;
}

export interface ActiveChatDto {
    id: string;
    title: string | null;
    model: string | null;
    agent_id: string | null;
    tool_ids: string[];
    chat_project_id: string | null;
    mode?: 'single' | 'multi_agent';
    synthesis_status?: ChatSynthesisStatus;
    agents?: ChatAgentRef[];
    messages: ChatMessageDto[];
}
