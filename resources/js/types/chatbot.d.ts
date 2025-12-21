export type ChatbotStatus = 'draft' | 'active' | 'inactive';

export interface ChatbotAppearance {
    primary_color: string;
    background_color: string;
    text_color: string;
    logo_url: string | null;
    position: 'bottom-right' | 'bottom-left';
    welcome_message: string;
    placeholder_text: string;
    widget_title: string;
}

export interface ChatbotBehavior {
    auto_open_delay: number;
    require_visitor_info: boolean;
    collect_email: boolean;
    collect_name: boolean;
    show_powered_by: boolean;
}

export interface ChatbotAdvanced {
    custom_css: string | null;
    custom_font_family: string | null;
}

export interface ChatbotConfig {
    appearance: ChatbotAppearance;
    behavior: ChatbotBehavior;
    advanced: ChatbotAdvanced;
}

export interface ChatbotAgent {
    id: string;
    name: string;
    type: 'triage' | 'knowledge' | 'action';
    status?: string;
}

export interface ChatbotAgentTeam {
    id: string;
    name: string;
    status?: string;
}

export interface Chatbot {
    id: string;
    user_id: number;
    organization_id: string | null;
    agent_id: string | null;
    agent_team_id: string | null;
    agent?: ChatbotAgent | null;
    agent_team?: ChatbotAgentTeam | null;
    name: string;
    description: string | null;
    status: ChatbotStatus;
    visibility: 'private' | 'organization';
    config: ChatbotConfig;
    allowed_origins: string[] | null;
    conversations_count?: number;
    sessions_count?: number;
    created_at: string;
    updated_at: string;
}

export interface WidgetSession {
    id: string;
    chatbot_id: string;
    session_token: string;
    visitor_email: string | null;
    visitor_name: string | null;
    visitor_metadata: Record<string, unknown> | null;
    last_activity_at: string | null;
    created_at: string;
}

export interface WidgetConversation {
    id: string;
    chatbot_id: string;
    widget_session_id: string;
    session?: WidgetSession;
    title: string | null;
    message_count: number;
    messages_count?: number;
    rating: number | null;
    feedback: string | null;
    is_resolved: boolean;
    is_abandoned: boolean;
    first_response_at: string | null;
    total_response_time_ms: number;
    created_at: string;
    updated_at: string;
}

export interface WidgetMessage {
    id: string;
    widget_conversation_id: string;
    role: 'user' | 'assistant' | 'system';
    content: string;
    tokens_used: number | null;
    model: string | null;
    metadata: Record<string, unknown> | null;
    response_time_ms: number | null;
    created_at: string;
}

export interface ChatbotApiToken {
    id: string;
    chatbot_id: string;
    name: string;
    token: string;
    abilities: string[] | null;
    last_used_at: string | null;
    expires_at: string | null;
    created_at: string;
}

export interface ChatbotStats {
    total_conversations: number;
    total_sessions: number;
    avg_rating: number | null;
    resolution_rate: number;
}

export interface PaginatedChatbots {
    data: Chatbot[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface PaginatedConversations {
    data: WidgetConversation[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface VisibilityOption {
    value: 'private' | 'organization';
    label: string;
    description: string;
}

export interface AnalyticsOverview {
    period: {
        start: string;
        end: string;
    };
    total_conversations: number;
    conversations_trend: number;
    total_messages: number;
    unique_sessions: number;
    avg_response_time_ms: number;
    avg_rating: number | null;
    total_ratings: number;
    resolved_count: number;
    abandoned_count: number;
    resolution_rate: number;
    messages_per_conversation: number;
}

export interface DailyData {
    date: string;
    conversations: number;
    messages: number;
    visitors: number;
}

export interface TopTopic {
    topic: string;
    count: number;
}

export interface RatingDistribution {
    [key: number]: number;
}

export interface ResponseTimeDistribution {
    fast: number;
    normal: number;
    slow: number;
    very_slow: number;
}
