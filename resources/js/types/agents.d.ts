export type AgentType = 'triage' | 'knowledge' | 'action';
export type AgentStatus = 'draft' | 'active' | 'inactive';

export interface AgentTypeOption {
    value: AgentType;
    label: string;
    description: string;
}

export interface ModelOption {
    value: string;
    label: string;
    provider?: string;
}

export interface TriageAgentConfig {
    temperature?: number;
    guardrails?: {
        content_filters?: boolean;
        safety_settings?: Record<string, unknown>;
    };
}

export interface KnowledgeAgentConfig {
    rag_params?: {
        chunk_size?: number;
        top_k?: number;
        similarity_threshold?: number;
    };
}

export interface ActionAgentConfig {
    tool_execution?: {
        timeout?: number;
        retry_count?: number;
    };
}

export type AgentConfig =
    | TriageAgentConfig
    | KnowledgeAgentConfig
    | ActionAgentConfig;

export interface KnowledgeBaseReference {
    id: string;
    name: string;
}

export interface ToolReference {
    id: string;
    name: string;
    type: string;
}

export interface Agent {
    id: string;
    user_id: number | null;
    agent_team_id: string | null;
    type: AgentType;
    name: string;
    description: string | null;
    keywords: string[] | null;
    status: AgentStatus;
    prompt_template: string | null;
    model: string;
    config: AgentConfig | null;
    knowledge_bases?: KnowledgeBaseReference[];
    tools?: ToolReference[];
    knowledge_bases_count?: number;
    tools_count?: number;
    created_at: string;
    updated_at: string;
}

export interface AgentTeam {
    id: string;
    user_id: number;
    name: string;
    description: string | null;
    keywords: string[] | null;
    status: AgentStatus;
    agents_count?: number;
    agents?: Agent[];
    created_at: string;
    updated_at: string;
    deleted_at: string | null;
}

export interface AgentFormData {
    type: AgentType;
    name: string;
    description: string;
    status: AgentStatus;
    prompt_template: string;
    model: string;
    config: AgentConfig;
}

export interface StandaloneAgentFormData {
    type: AgentType;
    name: string;
    description: string;
    prompt_template: string;
    model: string;
    config: AgentConfig;
    knowledge_base_ids: string[];
    tool_ids: string[];
}

export interface PaginatedAgentTeams {
    data: AgentTeam[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface PaginatedAgents {
    data: Agent[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface RecommendedModels {
    triage: string[];
    knowledge: string[];
    action: string[];
}
