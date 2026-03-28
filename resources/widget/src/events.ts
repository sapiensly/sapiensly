import type { WidgetEventCallback, WidgetEventType } from './types';

/**
 * Simple event emitter for widget events.
 */
export class EventEmitter {
    private listeners: Map<WidgetEventType, Set<WidgetEventCallback>> =
        new Map();

    /**
     * Subscribe to an event.
     */
    on(event: WidgetEventType, callback: WidgetEventCallback): () => void {
        if (!this.listeners.has(event)) {
            this.listeners.set(event, new Set());
        }
        this.listeners.get(event)!.add(callback);

        // Return unsubscribe function
        return () => this.off(event, callback);
    }

    /**
     * Unsubscribe from an event.
     */
    off(event: WidgetEventType, callback: WidgetEventCallback): void {
        const callbacks = this.listeners.get(event);
        if (callbacks) {
            callbacks.delete(callback);
        }
    }

    /**
     * Emit an event to all subscribers.
     */
    emit(event: WidgetEventType, data?: unknown): void {
        const callbacks = this.listeners.get(event);
        if (callbacks) {
            callbacks.forEach((callback) => {
                try {
                    callback(data);
                } catch (error) {
                    console.error(
                        `[Sapiensly] Error in event handler for "${event}":`,
                        error,
                    );
                }
            });
        }
    }

    /**
     * Remove all listeners.
     */
    removeAllListeners(): void {
        this.listeners.clear();
    }
}
