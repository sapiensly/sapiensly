import { ApiClient } from './api';
import { ErrorTracker } from './errors';
import { EventEmitter } from './events';
import { Storage } from './storage';
import type {
    Attachment,
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
import { buildResolutionPrompt } from './ui/resolution';

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

    /** Whether this conversation has already been asked if it was resolved. */
    private resolutionAsked = false;
    private abortStream: (() => void) | null = null;

    /**
     * Live-handoff polling. While a person holds the conversation the bot is
     * muted server-side, so there is no stream to read — their words arrive by
     * asking for what is new every few seconds.
     */
    private personPollTimer: ReturnType<typeof setInterval> | null = null;

    /** The newest message id the server has confirmed, for `after` polling. */
    private lastSeenMessageId: string | null = null;

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
            // Fetch configuration, then let the host page overlay its own look.
            // Only keys the caller actually passed win, so an override channel
            // can never blank a value the server did configure.
            this.config = await this.api.getConfig();
            if (this.options.appearance) {
                this.config.config.appearance = {
                    ...this.config.config.appearance,
                    ...Object.fromEntries(
                        Object.entries(this.options.appearance).filter(
                            ([, value]) =>
                                value !== undefined && value !== null,
                        ),
                    ),
                };
            }

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

            // Set once the transcript shows a person is mid-handoff; acted on
            // after the UI exists, so their next message has somewhere to land.
            let restoreListening: string | null = null;

            // Restore conversation from storage
            const savedConversation = this.storage.getConversation();
            if (savedConversation) {
                this.conversation = savedConversation;
                // Load existing messages
                try {
                    const page = await this.api.getMessagePage(
                        savedConversation.conversation_id,
                    );
                    this.messages = page.messages;
                    this.lastSeenMessageId =
                        page.messages[page.messages.length - 1]?.id ?? null;

                    // Reloading the page mid-handoff must not strand the visitor
                    // waiting on a person whose next message has nowhere to
                    // land. Resume listening where the transcript left off.
                    if (page.with_person) {
                        restoreListening = savedConversation.conversation_id;
                    }
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
                    onSend: (message, attachments) =>
                        this.sendMessage(message, attachments),
                    onUpload: (file) => this.uploadFile(file),
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

            if (restoreListening) {
                this.startListeningForPerson(restoreListening);
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
        this.stopListeningForPerson();
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
    /**
     * Upload a file for the current conversation, creating one if needed, and
     * return the stored attachment so the input can stage it for the next send.
     */
    private async uploadFile(file: File): Promise<Attachment> {
        const conversation = await this.ensureConversation();
        return this.api.uploadAttachment(conversation.conversation_id, file);
    }

    private async sendMessage(
        content: string,
        attachments: Attachment[] = [],
    ): Promise<void> {
        if (this.isStreaming || (!content.trim() && attachments.length === 0)) {
            return;
        }

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
                attachments: attachments.length ? attachments : undefined,
            };

            this.messages.push(userMessage);
            this.container?.addMessage(userMessage);
            this.events.emit('message:sent', userMessage);

            // Send to API
            const response = await this.api.sendMessage(
                conversation.conversation_id,
                content,
                attachments.map((a) => a.id),
            );

            // Update message ID
            userMessage.id = response.message_id;
            this.lastSeenMessageId = response.message_id;

            // A person has this conversation. Don't ask for a stream that cannot
            // come — hand the visitor back their composer and start listening
            // for the human instead.
            if (response.with_person) {
                this.isStreaming = false;
                this.container?.enableInput();
                this.startListeningForPerson(conversation.conversation_id);

                return;
            }

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

        this.askIfResolved();
    }

    /**
     * How often to ask for what a person has said.
     *
     * A support reply is typed by a human, so seconds of latency are invisible
     * to the visitor — this is nowhere near a chat protocol, and does not need
     * to be. Slow enough that an open widget costs a trickle of requests,
     * quick enough that an answer feels like it arrived, not like it was fetched.
     */
    private static readonly PERSON_POLL_MS = 4000;

    /**
     * Listen for a human's words while they hold the conversation.
     *
     * Polling and not a socket: this bundle runs inside strangers' pages, where
     * a WebSocket dependency is weight and a blocked port is someone else's
     * firewall — and an open SSE per waiting visitor would pin a PHP worker
     * each. The loop stops itself the moment the server says the bot has the
     * conversation back, so it lives exactly as long as a handoff does.
     */
    private startListeningForPerson(conversationId: string): void {
        if (this.personPollTimer) {
            return;
        }

        const poll = async (): Promise<void> => {
            try {
                const page = await this.api.getMessagePage(
                    conversationId,
                    this.lastSeenMessageId ?? undefined,
                );

                for (const message of page.messages) {
                    this.lastSeenMessageId = message.id;

                    // Our own message coming back from the server; it is already
                    // on screen from the moment it was typed.
                    if (message.role === 'user') {
                        continue;
                    }

                    this.messages.push(message);
                    this.container?.addMessage(message);
                    this.events.emit('message:received', message);
                    this.events.emit('message', message);
                }

                if (page.messages.length > 0) {
                    this.container?.scrollToBottom();
                }

                if (!page.with_person) {
                    this.stopListeningForPerson();
                }
            } catch (error) {
                // A failed poll is not a failed conversation — the network
                // blinked, or the page was backgrounded. Keep the loop alive and
                // let the next tick catch up; only a server that says the bot is
                // back ends it.
                this.errorTracker.capture(error as Error, {
                    phase: 'personPoll',
                });
            }
        };

        this.personPollTimer = setInterval(poll, Widget.PERSON_POLL_MS);
        void poll();
    }

    private stopListeningForPerson(): void {
        if (this.personPollTimer) {
            clearInterval(this.personPollTimer);
            this.personPollTimer = null;
        }
    }

    /**
     * Ask, once per conversation, whether the bot actually helped.
     *
     * Once — not after every answer: a prompt that repeats gets dismissed, and a
     * dismissed prompt measures nothing. Asked right under an answer, which is
     * the moment the visitor knows the answer.
     */
    private askIfResolved(): void {
        if (this.resolutionAsked || !this.conversation || !this.config) return;

        this.resolutionAsked = true;

        const conversationId = this.conversation.conversation_id;
        const prompt = buildResolutionPrompt(
            this.config.config.appearance,
            (resolved) => {
                // The endpoint wants a 1-5 rating; the two answers map to its
                // ends. `is_resolved` is the field that actually feeds the
                // owner's resolution metric.
                this.api
                    .submitFeedback(
                        conversationId,
                        resolved ? 5 : 1,
                        undefined,
                        resolved,
                    )
                    .catch((error) =>
                        this.errorTracker.capture(error as Error, {
                            phase: 'resolutionFeedback',
                        }),
                    );

                this.events.emit('resolution', { resolved });
            },
        );

        this.container?.appendToMessages(prompt);
    }
}
