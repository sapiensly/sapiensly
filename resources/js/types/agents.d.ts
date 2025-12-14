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
}

export interface Agent {
    id: number;
    agent_team_id: number;
    type: AgentType;
    name: string;
    description: string | null;
    status: AgentStatus;
    prompt_template: string | null;
    model: string;
    config: Record<string, unknown> | null;
    created_at: string;
    updated_at: string;
}

export interface AgentTeam {
    id: number;
    user_id: number;
    name: string;
    description: string | null;
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
    config: Record<string, unknown>;
}

export interface PaginatedAgentTeams {
    data: AgentTeam[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}
