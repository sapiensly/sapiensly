import type { ConversationData, SessionData } from './types';

const STORAGE_PREFIX = 'sapiensly_widget_';

/**
 * Storage service for persisting session and conversation data.
 */
export class Storage {
    private chatbotId: string;

    constructor(chatbotId: string) {
        this.chatbotId = chatbotId;
    }

    private getKey(key: string): string {
        return `${STORAGE_PREFIX}${this.chatbotId}_${key}`;
    }

    /**
     * Get session data from storage.
     */
    getSession(): SessionData | null {
        try {
            const data = localStorage.getItem(this.getKey('session'));
            if (data) {
                return JSON.parse(data);
            }
        } catch (error) {
            console.warn('[Sapiensly] Failed to read session from storage:', error);
        }
        return null;
    }

    /**
     * Save session data to storage.
     */
    setSession(session: SessionData): void {
        try {
            localStorage.setItem(this.getKey('session'), JSON.stringify(session));
        } catch (error) {
            console.warn('[Sapiensly] Failed to save session to storage:', error);
        }
    }

    /**
     * Get current conversation data.
     */
    getConversation(): ConversationData | null {
        try {
            const data = localStorage.getItem(this.getKey('conversation'));
            if (data) {
                return JSON.parse(data);
            }
        } catch (error) {
            console.warn('[Sapiensly] Failed to read conversation from storage:', error);
        }
        return null;
    }

    /**
     * Save current conversation data.
     */
    setConversation(conversation: ConversationData): void {
        try {
            localStorage.setItem(this.getKey('conversation'), JSON.stringify(conversation));
        } catch (error) {
            console.warn('[Sapiensly] Failed to save conversation to storage:', error);
        }
    }

    /**
     * Clear conversation data (start fresh).
     */
    clearConversation(): void {
        try {
            localStorage.removeItem(this.getKey('conversation'));
        } catch (error) {
            console.warn('[Sapiensly] Failed to clear conversation from storage:', error);
        }
    }

    /**
     * Clear all widget data.
     */
    clear(): void {
        try {
            localStorage.removeItem(this.getKey('session'));
            localStorage.removeItem(this.getKey('conversation'));
        } catch (error) {
            console.warn('[Sapiensly] Failed to clear storage:', error);
        }
    }
}
