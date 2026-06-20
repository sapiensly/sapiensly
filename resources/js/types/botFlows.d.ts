export type BotFlowNodeType =
    | 'start'
    | 'menu'
    | 'condition'
    | 'agent'
    | 'agent_handoff'
    | 'message'
    | 'connector'
    | 'input'
    | 'human_handoff'
    | 'end';

export type AgentRole = 'triage' | 'knowledge' | 'action';

export interface BotFlowDefinition {
    nodes: BotFlowNodeData[];
    edges: BotFlowEdgeData[];
    viewport?: { x: number; y: number; zoom: number };
}

export interface BotFlowNodeData {
    id: string;
    type: BotFlowNodeType;
    position: { x: number; y: number };
    data:
        | StartNodeConfig
        | MenuNodeConfig
        | ConditionNodeConfig
        | AgentNodeConfig
        | AgentHandoffNodeConfig
        | MessageNodeConfig
        | ConnectorNodeConfig
        | InputNodeConfig
        | HumanHandoffNodeConfig
        | EndNodeConfig;
}

export interface BotFlowEdgeData {
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

export interface AgentLayerConfig {
    enabled: boolean;
    agent_id: string | null;
    agent_name?: string | null;
}

export interface AgentNodeConfig {
    /** Which role this agent plays in the bot's roster. */
    role: AgentRole;
    agent_id: string | null;
    agent_name?: string | null;
}

export interface AgentHandoffNodeConfig {
    target_agent: 'knowledge' | 'action' | 'triage_llm';
    context?: string;
    message?: string;
    /** UI mode: a single agent (default) vs the Triage/Knowledge/Tools team. */
    mode?: 'agent' | 'multi_agent';
    layers?: {
        triage: AgentLayerConfig;
        knowledge: AgentLayerConfig;
        tools: AgentLayerConfig;
    };
}

export interface MessageNodeConfig {
    message: string;
}

export interface ConnectorNodeConfig {
    /** '__start__' to go to flow start, or a node ID of a menu node */
    target_node_id: string;
    target_label?: string;
}

export type InputType = 'text' | 'email' | 'number' | 'phone';

export interface InputNodeConfig {
    /** The question shown to the user to elicit the value. */
    prompt: string;
    /** Key the captured value is stored under in the flow's variable bag. */
    variable: string;
    /** Validation applied to the reply; re-prompts until it passes. */
    input_type?: InputType;
    /** Shown when the reply fails validation. Falls back to `prompt`. */
    error_message?: string;
}

export interface HumanHandoffNodeConfig {
    /** Optional notice shown to the user before handing off. */
    message?: string;
    /** Internal note describing why the conversation was escalated. */
    reason?: string;
    /** Whether to notify the team of the escalation. Defaults to true. */
    notify?: boolean;
}

export interface EndNodeConfig {
    action: 'resume_conversation' | 'close_conversation';
    message?: string;
}

export interface BotFlow {
    id: string;
    agent_id: string;
    name: string;
    description: string | null;
    status: 'draft' | 'active' | 'inactive';
    definition: BotFlowDefinition;
    version: number;
    created_at: string;
    updated_at: string;
}
