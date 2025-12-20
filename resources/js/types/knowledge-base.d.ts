export type KnowledgeBaseStatus = 'pending' | 'processing' | 'ready' | 'failed';
export type DocumentType = 'pdf' | 'txt' | 'docx' | 'md' | 'url' | 'csv' | 'json';

export interface DocumentTypeOption {
    value: DocumentType;
    label: string;
}

export interface KnowledgeBaseConfig {
    chunk_size?: number;
    chunk_overlap?: number;
}

export interface KnowledgeBaseDocument {
    id: string;
    knowledge_base_id: string;
    type: DocumentType;
    source: string;
    original_filename: string | null;
    content: string | null;
    metadata: Record<string, unknown> | null;
    embedding_status: KnowledgeBaseStatus;
    error_message: string | null;
    file_path: string | null;
    file_size: number | null;
    created_at: string;
    updated_at: string;
}

export interface KnowledgeBase {
    id: string;
    user_id: number;
    name: string;
    description: string | null;
    keywords: string[] | null;
    status: KnowledgeBaseStatus;
    config: KnowledgeBaseConfig | null;
    document_count: number;
    chunk_count: number;
    documents?: KnowledgeBaseDocument[];
    documents_count?: number;
    created_at: string;
    updated_at: string;
    deleted_at: string | null;
}

export interface PaginatedKnowledgeBases {
    data: KnowledgeBase[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}
