import type { AgentStatus } from './agents';

export type ToolType = 'function' | 'mcp' | 'group';

export interface ToolTypeOption {
    value: ToolType;
    label: string;
    description: string;
}

export interface FunctionConfig {
    name?: string;
    description?: string;
    parameters?: {
        type: string;
        properties?: Record<string, unknown>;
        required?: string[];
    };
}

export interface McpConfig {
    endpoint?: string;
    auth_type?: 'none' | 'bearer' | 'api_key' | 'basic';
    auth_config?: Record<string, unknown>;
}

export interface GroupConfig {
    tool_ids?: number[];
}

export type ToolConfig = FunctionConfig | McpConfig | GroupConfig;

export interface ToolGroupItem {
    id: number;
    tool_group_id: number;
    tool_id: number;
    order: number;
    tool?: ToolReference;
}

export interface ToolReference {
    id: number;
    name: string;
    type: ToolType;
}

export interface Tool {
    id: number;
    user_id: number;
    type: ToolType;
    name: string;
    description: string | null;
    config: ToolConfig | null;
    status: AgentStatus;
    is_validated: boolean;
    last_validated_at: string | null;
    group_items?: ToolGroupItem[];
    created_at: string;
    updated_at: string;
    deleted_at: string | null;
}

export interface PaginatedTools {
    data: Tool[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}
