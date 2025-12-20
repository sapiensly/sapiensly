export type Visibility = 'private' | 'organization';
export type DocumentType = 'pdf' | 'txt' | 'docx' | 'md' | 'csv' | 'json';
export type EmbeddingStatus = 'pending' | 'processing' | 'ready' | 'failed';

export interface VisibilityOption {
    value: Visibility;
    label: string;
    description: string;
}

export interface DocumentTypeOption {
    value: DocumentType;
    label: string;
}

export interface Folder {
    id: string;
    user_id: number;
    organization_id: string | null;
    parent_id: string | null;
    name: string;
    visibility: Visibility;
    children?: Folder[];
    documents_count?: number;
    user?: { id: number; name: string };
    created_at: string;
    updated_at: string;
}

export interface Document {
    id: string;
    user_id: number;
    organization_id: string | null;
    folder_id: string | null;
    name: string;
    keywords: string[] | null;
    type: DocumentType;
    original_filename: string | null;
    file_path: string | null;
    file_size: number | null;
    visibility: Visibility;
    metadata: Record<string, unknown> | null;
    formatted_file_size?: string;
    user?: { id: number; name: string };
    folder?: { id: string; name: string };
    knowledge_bases?: { id: string; name: string }[];
    knowledge_bases_count?: number;
    created_at: string;
    updated_at: string;
    deleted_at: string | null;
}

export interface GroupedFolders {
    my: Folder[];
    organization: Folder[];
}

export interface PaginatedDocuments {
    data: Document[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
}
