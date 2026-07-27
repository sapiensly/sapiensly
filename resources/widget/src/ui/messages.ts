import type { Attachment, Message } from '../types';
import { parseMarkdown } from './markdown';

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
        messageEl.dataset.messageId = message.id;

        // A person wrote this one. The visitor is owed that difference — an
        // unlabelled human reply reads as the bot, which makes the whole handoff
        // pointless, and an unlabelled bot reply after a person spoke is worse.
        if (message.human) {
            messageEl.classList.add('sapiensly-message-human');

            const byline = document.createElement('span');
            byline.className = 'sapiensly-message-byline';
            // textContent, always: this is a name from the tenant's user table
            // arriving in a page we do not own.
            byline.textContent = message.sender_name || '';
            if (byline.textContent !== '') {
                messageEl.appendChild(byline);
            }
        }

        const body = document.createElement('div');
        body.className = 'sapiensly-message-body';

        // User messages: escape HTML, Assistant messages: render markdown
        if (message.role === 'user') {
            body.textContent = message.content;
        } else {
            body.innerHTML = parseMarkdown(message.content);
        }

        messageEl.appendChild(body);

        if (message.attachments?.length) {
            messageEl.appendChild(this.renderAttachments(message.attachments));
        }

        this.element.appendChild(messageEl);
        this.scrollToBottom();

        return messageEl;
    }

    /**
     * Render a message's attachments as a row of links to the stored files.
     */
    private renderAttachments(attachments: Attachment[]): HTMLDivElement {
        const row = document.createElement('div');
        row.className = 'sapiensly-message-attachments';

        for (const attachment of attachments) {
            const link = document.createElement('a');
            link.className = 'sapiensly-message-attachment';
            link.href = attachment.url;
            link.target = '_blank';
            link.rel = 'noopener';
            link.textContent = attachment.original_name;
            row.appendChild(link);
        }

        return row;
    }

    /**
     * Update a message's content (for streaming).
     */
    updateMessage(messageId: string, content: string): void {
        const messageEl = this.element.querySelector(
            `[data-message-id="${messageId}"]`,
        ) as HTMLDivElement;

        if (messageEl) {
            // Write into the body, not the bubble: the bubble may also hold the
            // byline that says a person is speaking, and replacing its innerHTML
            // would silently drop that attribution mid-stream.
            const body =
                (messageEl.querySelector(
                    '.sapiensly-message-body',
                ) as HTMLDivElement | null) ?? messageEl;

            // Check if it's an assistant message by class
            const isAssistant = messageEl.classList.contains(
                'sapiensly-message-assistant',
            );
            if (isAssistant) {
                body.innerHTML = parseMarkdown(content);
            } else {
                body.textContent = content;
            }
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
     * Append a raw DOM element to the messages area.
     */
    appendElement(element: HTMLElement): void {
        this.hideWelcome();
        this.element.appendChild(element);
        this.scrollToBottom();
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
