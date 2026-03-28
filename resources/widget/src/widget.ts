import { ApiClient } from './api';
import { ErrorTracker } from './errors';
import { EventEmitter } from './events';
import { Storage } from './storage';
import type {
    ConversationData,
    Message,
    SessionData,
    StreamEvent,
    VisitorInfo,
    WidgetConfig,
    WidgetEventCallback,
    WidgetEventType,
    WidgetOptions,
} from './types';
import { Container } from './ui/container';

/**
 * Main Widget class that orchestrates all components.
 */
export class Widget {
    private options: WidgetOptions;
    private api: ApiClient;
    private storage: Storage | null = null;
    private events: EventEmitter;
    private container: Container | null = null;
    private errorTracker: ErrorTracker;
    private cleanupErrorHandlers: (() => void) | null = null;

    private config: WidgetConfig | null = null;
    private session: SessionData | null = null;
    private conversation: ConversationData | null = null;
    private messages: Message[] = [];

    private isInitialized = false;
    private isStreaming = false;
    private currentStreamingMessageId: string | null = null;
    private streamContent = '';
    private abortStream: (() => void) | null = null;

    constructor(options: WidgetOptions) {
        this.options = options;
        this.events = new EventEmitter();

        // Determine base URL
        const baseUrl = options.baseUrl || this.detectBaseUrl();
        this.api = new ApiClient(baseUrl, options.token);

        // Initialize error tracking
        this.errorTracker = new ErrorTracker(baseUrl);
        this.cleanupErrorHandlers = this.errorTracker.installGlobalHandlers();
    }

    /**
     * Detect the base URL from the script tag.
     */
    private detectBaseUrl(): string {
        const scripts = document.querySelectorAll('script[src*="widget.js"]');
        for (const script of scripts) {
            const src = script.getAttribute('src');
            if (src) {
                const url = new URL(src, window.location.href);
                return `${url.protocol}//${url.host}`;
            }
        }
        return window.location.origin;
    }

    /**
     * Initialize the widget.
     */
    async init(): Promise<void> {
        if (this.isInitialized) return;

        try {
            // Fetch configuration
            this.config = await this.api.getConfig();

            // Set error tracking context
            this.errorTracker.setContext(this.config.chatbot_id);

            // Initialize storage
            this.storage = new Storage(this.config.chatbot_id);

            // Restore session from storage
            const savedSession = this.storage.getSession();
            if (savedSession) {
                this.session = savedSession;
                this.api.setSessionToken(savedSession.session_token);
            }

            // Restore conversation from storage
            const savedConversation = this.storage.getConversation();
            if (savedConversation) {
                this.conversation = savedConversation;
                // Load existing messages
                try {
                    this.messages = await this.api.getMessages(
                        savedConversation.conversation_id,
                    );
                } catch {
                    // Conversation might have expired, start fresh
                    this.storage.clearConversation();
                    this.conversation = null;
                }
            }

            // Create UI
            this.container = new Container(
                this.config.config.appearance,
                this.config.config.behavior,
                {
                    onSend: (message) => this.sendMessage(message),
                    onOpen: () => this.handleOpen(),
                    onClose: () => this.handleClose(),
                },
            );

            // Mount to DOM
            this.container.mount();

            // Add existing messages to UI
            for (const message of this.messages) {
                this.container.addMessage(message);
            }

            this.isInitialized = true;
            this.events.emit('ready');

            // Auto-open if configured
            const autoOpenDelay = this.config.config.behavior.auto_open_delay;
            if (autoOpenDelay > 0) {
                setTimeout(() => this.open(), autoOpenDelay);
            }
        } catch (error) {
            this.errorTracker.capture(error as Error, { phase: 'init' });
            this.events.emit('error', error);
            throw error;
        }
    }

    /**
     * Open the widget.
     */
    open(): void {
        this.container?.open();
    }

    /**
     * Close the widget.
     */
    close(): void {
        this.container?.close();
    }

    /**
     * Toggle the widget.
     */
    toggle(): void {
        this.container?.toggle();
    }

    /**
     * Identify the visitor.
     */
    async identify(info: VisitorInfo): Promise<void> {
        if (!this.session) {
            // Create session with visitor info
            await this.ensureSession(info);
        } else {
            // Update existing session
            await this.api.updateSession(this.session.session_id, info);
            this.session.visitor_email = info.email;
            this.session.visitor_name = info.name;
            this.storage?.setSession(this.session);
        }
    }

    /**
     * Subscribe to an event.
     */
    on(event: WidgetEventType, callback: WidgetEventCallback): () => void {
        return this.events.on(event, callback);
    }

    /**
     * Destroy the widget.
     */
    destroy(): void {
        if (this.abortStream) {
            this.abortStream();
        }
        if (this.cleanupErrorHandlers) {
            this.cleanupErrorHandlers();
        }
        this.container?.destroy();
        this.events.removeAllListeners();
        this.isInitialized = false;
    }

    /**
     * Handle widget open.
     */
    private handleOpen(): void {
        this.events.emit('open');
    }

    /**
     * Handle widget close.
     */
    private handleClose(): void {
        this.events.emit('close');
    }

    /**
     * Ensure we have a session.
     */
    private async ensureSession(
        visitorInfo?: VisitorInfo,
    ): Promise<SessionData> {
        if (this.session) {
            return this.session;
        }

        this.session = await this.api.createSession(visitorInfo);
        this.storage?.setSession(this.session);

        // Update error tracker context with session
        if (this.config) {
            this.errorTracker.setContext(
                this.config.chatbot_id,
                this.session.session_id,
            );
        }

        this.events.emit('session:created', this.session);

        return this.session;
    }

    /**
     * Ensure we have a conversation.
     */
    private async ensureConversation(): Promise<ConversationData> {
        if (this.conversation) {
            return this.conversation;
        }

        const session = await this.ensureSession();
        const result = await this.api.createConversation(session.session_token);

        this.conversation = {
            conversation_id: result.conversation_id,
            created_at: result.created_at,
        };

        this.storage?.setConversation(this.conversation);
        this.events.emit('conversation:created', this.conversation);

        return this.conversation;
    }

    /**
     * Send a message.
     */
    private async sendMessage(content: string): Promise<void> {
        if (this.isStreaming || !content.trim()) return;

        try {
            this.isStreaming = true;
            this.container?.disableInput();

            // Ensure we have session and conversation
            const conversation = await this.ensureConversation();

            // Create user message
            const userMessage: Message = {
                id: `temp-${Date.now()}`,
                role: 'user',
                content,
                created_at: new Date().toISOString(),
            };

            this.messages.push(userMessage);
            this.container?.addMessage(userMessage);
            this.events.emit('message:sent', userMessage);

            // Send to API
            const response = await this.api.sendMessage(
                conversation.conversation_id,
                content,
            );

            // Update message ID
            userMessage.id = response.message_id;

            // Show typing indicator
            this.container?.showTyping();

            // Create placeholder for assistant message
            const assistantMessageId = `streaming-${Date.now()}`;
            this.currentStreamingMessageId = assistantMessageId;
            this.streamContent = '';

            const assistantMessage: Message = {
                id: assistantMessageId,
                role: 'assistant',
                content: '',
                created_at: new Date().toISOString(),
                isStreaming: true,
            };

            // Stream the response
            this.abortStream = this.api.streamResponse(
                conversation.conversation_id,
                (event) => this.handleStreamEvent(event, assistantMessage),
                (error) => this.handleStreamError(error),
                () => this.handleStreamComplete(assistantMessage),
            );
        } catch (error) {
            this.errorTracker.capture(error as Error, { phase: 'sendMessage' });
            this.events.emit('error', error);
            this.container?.hideTyping();
            this.container?.enableInput();
            this.isStreaming = false;
        }
    }

    /**
     * Handle a stream event.
     */
    private handleStreamEvent(event: StreamEvent, message: Message): void {
        if ('error' in event) {
            this.handleStreamError(new Error(event.error));
            return;
        }

        if (event.type === 'content') {
            // First content - hide typing and add message
            if (this.streamContent === '') {
                this.container?.hideTyping();
                this.container?.addMessage(message);
            }

            this.streamContent += event.content;
            message.content = this.streamContent;
            this.container?.updateMessage(message.id, this.streamContent);
        }

        // Flow events
        if (event.type === 'flow_start') {
            this.events.emit('flow:start', {
                flow_id: event.flow_id,
                flow_name: event.flow_name,
            });
        } else if (event.type === 'flow_menu') {
            this.container?.hideTyping();

            // Import FlowMenu dynamically to keep initial bundle small
            import('./ui/flow-menu').then(({ FlowMenu }) => {
                const menu = new FlowMenu(
                    event.message,
                    event.options,
                    (_optionId: string, label: string) => {
                        this.sendMessage(label);
                    },
                );
                this.container?.appendToMessages(menu.getElement());
                this.container?.scrollToBottom();
            });

            this.events.emit('flow:menu', {
                message: event.message,
                options: event.options,
            });
        } else if (event.type === 'flow_message') {
            this.container?.hideTyping();

            const flowMsg: Message = {
                id: `flow-msg-${Date.now()}`,
                role: 'assistant',
                content: event.content,
                created_at: new Date().toISOString(),
            };
            this.messages.push(flowMsg);
            this.container?.addMessage(flowMsg);
        } else if (event.type === 'flow_end') {
            this.events.emit('flow:end', { action: event.action });
        }

        // Emit events for tool calls, knowledge bases, etc.
        if (event.type === 'tool_call') {
            this.events.emit('message', {
                type: 'tool_call',
                tool: event.tool,
            });
        } else if (event.type === 'knowledge_base') {
            this.events.emit('message', {
                type: 'knowledge_base',
                name: event.name,
            });
        }
    }

    /**
     * Handle stream error.
     */
    private handleStreamError(error: Error): void {
        this.errorTracker.capture(error, { phase: 'stream' });
        this.container?.hideTyping();
        this.container?.enableInput();
        this.isStreaming = false;
        this.abortStream = null;
        this.events.emit('error', error);
    }

    /**
     * Handle stream complete.
     */
    private handleStreamComplete(message: Message): void {
        message.isStreaming = false;
        this.messages.push(message);
        this.events.emit('message:received', message);
        this.events.emit('message', message);

        this.container?.hideTyping();
        this.container?.enableInput();
        this.isStreaming = false;
        this.abortStream = null;
        this.currentStreamingMessageId = null;
        this.streamContent = '';
    }
}
