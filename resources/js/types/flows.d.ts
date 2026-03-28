export type FlowNodeType =
    | 'start'
    | 'menu'
    | 'condition'
    | 'agent_handoff'
    | 'message'
    | 'end';

export interface FlowDefinition {
    nodes: FlowNodeData[];
    edges: FlowEdgeData[];
    viewport?: { x: number; y: number; zoom: number };
}

export interface FlowNodeData {
    id: string;
    type: FlowNodeType;
    position: { x: number; y: number };
    data:
        | StartNodeConfig
        | MenuNodeConfig
        | ConditionNodeConfig
        | AgentHandoffNodeConfig
        | MessageNodeConfig
        | EndNodeConfig;
}

export interface FlowEdgeData {
    id: string;
    source: string;
    target: string;
    sourceHandle?: string;
    label?: string;
}

export interface StartNodeConfig {
    trigger: 'conversation_start' | 'keyword' | 'always';
    keywords?: string[];
}

export interface MenuNodeConfig {
    message: string;
    options: MenuOption[];
}

export interface MenuOption {
    id: string;
    label: string;
    value?: string;
}

export interface ConditionNodeConfig {
    match_type: 'exact' | 'contains' | 'regex' | 'llm_classification';
    rules: ConditionRule[];
}

export interface ConditionRule {
    id: string;
    pattern: string;
    label?: string;
}

export interface AgentHandoffNodeConfig {
    target_agent: 'knowledge' | 'action' | 'triage_llm';
    context?: string;
    message?: string;
}

export interface MessageNodeConfig {
    message: string;
}

export interface EndNodeConfig {
    action: 'resume_conversation' | 'close_conversation';
    message?: string;
}

export interface Flow {
    id: string;
    agent_id: string;
    name: string;
    description: string | null;
    status: 'draft' | 'active' | 'inactive';
    definition: FlowDefinition;
    version: number;
    created_at: string;
    updated_at: string;
}
