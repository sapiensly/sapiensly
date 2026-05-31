export interface ChatModelOption {
    value: string;
    label: string;
    provider: string;
}

export interface ChatAttachmentDto {
    id: string;
    original_name: string;
    mime: string;
    size_bytes: number;
    url: string;
}

export type ChatMessageStatus = 'pending' | 'streaming' | 'complete' | 'error';

export interface ChatMessageDto {
    id: string;
    role: 'user' | 'assistant' | 'system';
    content: string | null;
    model: string | null;
    status: ChatMessageStatus;
    error: string | null;
    created_at: string | null;
    attachments: ChatAttachmentDto[];
}

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
}

export interface ActiveChatDto {
    id: string;
    title: string | null;
    model: string | null;
    agent_id: string | null;
    tool_ids: string[];
    chat_project_id: string | null;
    messages: ChatMessageDto[];
}
