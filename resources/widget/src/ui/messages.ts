import type { Message } from '../types';

/**
 * Messages list component.
 */
export class Messages {
    private element: HTMLDivElement;
    private welcomeElement: HTMLDivElement | null = null;

    constructor(welcomeMessage?: string) {
        this.element = this.createElement();

        if (welcomeMessage) {
            this.showWelcome(welcomeMessage);
        }
    }

    private createElement(): HTMLDivElement {
        const container = document.createElement('div');
        container.className = 'sapiensly-messages';
        return container;
    }

    /**
     * Show welcome message.
     */
    showWelcome(message: string): void {
        if (this.welcomeElement) return;

        this.welcomeElement = document.createElement('div');
        this.welcomeElement.className = 'sapiensly-welcome';
        this.welcomeElement.textContent = message;
        this.element.appendChild(this.welcomeElement);
    }

    /**
     * Hide welcome message.
     */
    hideWelcome(): void {
        if (this.welcomeElement) {
            this.welcomeElement.remove();
            this.welcomeElement = null;
        }
    }

    /**
     * Add a message to the list.
     */
    addMessage(message: Message): HTMLDivElement {
        this.hideWelcome();

        const messageEl = document.createElement('div');
        messageEl.className = `sapiensly-message sapiensly-message-${message.role}`;
        messageEl.textContent = message.content;
        messageEl.dataset.messageId = message.id;

        this.element.appendChild(messageEl);
        this.scrollToBottom();

        return messageEl;
    }

    /**
     * Update a message's content (for streaming).
     */
    updateMessage(messageId: string, content: string): void {
        const messageEl = this.element.querySelector(
            `[data-message-id="${messageId}"]`
        ) as HTMLDivElement;

        if (messageEl) {
            messageEl.textContent = content;
            this.scrollToBottom();
        }
    }

    /**
     * Add typing indicator.
     */
    showTyping(): HTMLDivElement {
        const typing = document.createElement('div');
        typing.className = 'sapiensly-typing';
        typing.innerHTML = `
            <div class="sapiensly-typing-dot"></div>
            <div class="sapiensly-typing-dot"></div>
            <div class="sapiensly-typing-dot"></div>
        `;
        typing.dataset.typing = 'true';

        this.element.appendChild(typing);
        this.scrollToBottom();

        return typing;
    }

    /**
     * Remove typing indicator.
     */
    hideTyping(): void {
        const typing = this.element.querySelector('[data-typing="true"]');
        if (typing) {
            typing.remove();
        }
    }

    /**
     * Scroll to the bottom of the messages.
     */
    scrollToBottom(): void {
        requestAnimationFrame(() => {
            this.element.scrollTop = this.element.scrollHeight;
        });
    }

    /**
     * Clear all messages.
     */
    clear(): void {
        this.element.innerHTML = '';
        this.welcomeElement = null;
    }

    /**
     * Get the DOM element.
     */
    getElement(): HTMLDivElement {
        return this.element;
    }
}
