import type { WidgetError } from './types';

/**
 * Error tracking for the widget.
 *
 * Captures errors and optionally reports them to the backend.
 */
export class ErrorTracker {
    private baseUrl: string;
    private chatbotId: string | null = null;
    private sessionId: string | null = null;
    private enabled = true;
    private errorQueue: WidgetError[] = [];
    private maxQueueSize = 10;

    constructor(baseUrl: string) {
        this.baseUrl = baseUrl;
    }

    /**
     * Set context for error reports.
     */
    setContext(chatbotId: string, sessionId?: string): void {
        this.chatbotId = chatbotId;
        this.sessionId = sessionId || null;
    }

    /**
     * Enable or disable error reporting.
     */
    setEnabled(enabled: boolean): void {
        this.enabled = enabled;
    }

    /**
     * Capture an error.
     */
    capture(error: Error | string, context?: Record<string, unknown>): void {
        if (!this.enabled) return;

        const widgetError: WidgetError = {
            message: typeof error === 'string' ? error : error.message,
            stack: typeof error === 'string' ? undefined : error.stack,
            context: {
                ...context,
                chatbotId: this.chatbotId,
                sessionId: this.sessionId,
                url: window.location.href,
                userAgent: navigator.userAgent,
                timestamp: new Date().toISOString(),
            },
        };

        // Add to queue
        this.errorQueue.push(widgetError);
        if (this.errorQueue.length > this.maxQueueSize) {
            this.errorQueue.shift();
        }

        // Report to backend
        this.report(widgetError);
    }

    /**
     * Report error to backend.
     */
    private async report(error: WidgetError): Promise<void> {
        if (!this.chatbotId) return;

        try {
            await fetch(`${this.baseUrl}/api/widget/v1/errors`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    chatbot_id: this.chatbotId,
                    session_id: this.sessionId,
                    error: error.message,
                    stack: error.stack,
                    context: error.context,
                }),
            });
        } catch {
            // Silently fail - don't want error reporting to cause more errors
        }
    }

    /**
     * Get captured errors (for debugging).
     */
    getErrors(): WidgetError[] {
        return [...this.errorQueue];
    }

    /**
     * Clear error queue.
     */
    clear(): void {
        this.errorQueue = [];
    }

    /**
     * Install global error handlers.
     */
    installGlobalHandlers(): () => void {
        const errorHandler = (event: ErrorEvent) => {
            this.capture(event.error || event.message, {
                type: 'uncaught',
                filename: event.filename,
                lineno: event.lineno,
                colno: event.colno,
            });
        };

        const rejectionHandler = (event: PromiseRejectionEvent) => {
            const error =
                event.reason instanceof Error
                    ? event.reason
                    : new Error(String(event.reason));
            this.capture(error, { type: 'unhandledRejection' });
        };

        window.addEventListener('error', errorHandler);
        window.addEventListener('unhandledrejection', rejectionHandler);

        // Return cleanup function
        return () => {
            window.removeEventListener('error', errorHandler);
            window.removeEventListener('unhandledrejection', rejectionHandler);
        };
    }
}
