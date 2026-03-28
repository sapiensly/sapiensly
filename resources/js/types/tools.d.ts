import type { AgentStatus } from './agents';

export type ToolType =
    | 'function'
    | 'mcp'
    | 'group'
    | 'rest_api'
    | 'graphql'
    | 'database';

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
    auth_config_is_set?: boolean;
}

export interface GroupConfig {
    tool_ids?: string[];
}

export interface RestApiConfig {
    base_url?: string;
    method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
    path?: string;
    headers?: Record<string, string>;
    auth_type?: 'none' | 'bearer' | 'api_key' | 'basic' | 'oauth2';
    auth_config?: Record<string, unknown>;
    auth_config_is_set?: boolean;
    request_body_template?: string;
    response_mapping?: Record<string, unknown>;
}

export interface GraphqlConfig {
    endpoint?: string;
    operation_type?: 'query' | 'mutation';
    operation?: string;
    variables_template?: Record<string, unknown>;
    auth_type?: 'none' | 'bearer' | 'api_key';
    auth_config?: Record<string, unknown>;
    auth_config_is_set?: boolean;
    response_mapping?: Record<string, unknown>;
}

export interface DatabaseConfig {
    driver?: 'pgsql' | 'mysql' | 'sqlite' | 'sqlsrv';
    host?: string;
    port?: number;
    database?: string;
    username?: string;
    username_is_set?: boolean;
    password?: string;
    password_is_set?: boolean;
    query_template?: string;
    read_only?: boolean;
}

export type ToolConfig =
    | FunctionConfig
    | McpConfig
    | GroupConfig
    | RestApiConfig
    | GraphqlConfig
    | DatabaseConfig;

export interface ToolGroupItem {
    id: number;
    tool_group_id: string;
    tool_id: string;
    order: number;
    tool?: ToolReference;
}

export interface ToolReference {
    id: string;
    name: string;
    type: ToolType;
}

export interface Tool {
    id: string;
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
