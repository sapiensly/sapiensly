/**
 * Widget configuration from the server.
 */
export interface WidgetConfig {
    chatbot_id: string;
    name: string;
    config: {
        appearance: AppearanceConfig;
        behavior: BehaviorConfig;
    };
}

export interface AppearanceConfig {
    primary_color: string;
    background_color: string;
    text_color: string;
    logo_url: string | null;
    position: 'bottom-right' | 'bottom-left';
    welcome_message: string;
    placeholder_text: string;
    widget_title: string;
}

export interface BehaviorConfig {
    auto_open_delay: number;
    require_visitor_info: boolean;
    collect_email: boolean;
    collect_name: boolean;
    show_powered_by: boolean;
}

/**
 * Session data stored locally.
 */
export interface SessionData {
    session_id: string;
    session_token: string;
    visitor_email?: string;
    visitor_name?: string;
    created_at: string;
}

/**
 * Conversation data.
 */
export interface ConversationData {
    conversation_id: string;
    created_at: string;
}

/**
 * Message in a conversation.
 */
export interface Message {
    id: string;
    role: 'user' | 'assistant' | 'system';
    content: string;
    created_at: string;
    isStreaming?: boolean;
}

/**
 * Visitor identification data.
 */
export interface VisitorInfo {
    email?: string;
    name?: string;
    metadata?: Record<string, unknown>;
}

/**
 * Widget initialization options.
 */
export interface WidgetOptions {
    token: string;
    baseUrl?: string;
}

/**
 * SSE event types from the stream.
 */
export type StreamEvent =
    | { type: 'content'; content: string }
    | { type: 'tool_call'; tool: string }
    | { type: 'knowledge_base'; name: string; id?: string }
    | { type: 'execution_plan'; steps: unknown[] }
    | { type: 'step_start'; step: number; agent: string }
    | { type: 'step_complete'; step: number; response: string }
    | { type: 'consolidating' }
    | { type: 'flow_start'; flow_id: string; flow_name: string }
    | { type: 'flow_menu'; message: string; options: FlowMenuOption[] }
    | { type: 'flow_message'; content: string }
    | { type: 'flow_end'; action: string }
    | { type: 'flow_await_input'; input_type: 'menu_selection' | 'text' }
    | { type: 'done' }
    | { error: string };

export interface FlowMenuOption {
    id: string;
    label: string;
}

/**
 * Widget event types.
 */
export type WidgetEventType =
    | 'ready'
    | 'open'
    | 'close'
    | 'message'
    | 'message:sent'
    | 'message:received'
    | 'error'
    | 'session:created'
    | 'conversation:created'
    | 'flow:start'
    | 'flow:menu'
    | 'flow:end';

export type WidgetEventCallback = (data?: unknown) => void;

/**
 * Widget error for tracking.
 */
export interface WidgetError {
    message: string;
    stack?: string;
    context?: Record<string, unknown>;
}
