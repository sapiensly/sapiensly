import type { AppearanceConfig, BehaviorConfig, Message } from '../types';
import { Bubble } from './bubble';
import { icons } from './icons';
import { Input } from './input';
import { Messages } from './messages';
import { generateStyles } from './styles';

/**
 * Main container component that holds the entire widget UI.
 */
export class Container {
    private container: HTMLDivElement;
    private styleElement: HTMLStyleElement;
    private bubble: Bubble;
    private window: HTMLDivElement;
    private messages: Messages;
    private input: Input;
    private isOpen = false;

    private onSend: (message: string) => void;
    private onOpen: () => void;
    private onClose: () => void;

    constructor(
        appearance: AppearanceConfig,
        behavior: BehaviorConfig,
        callbacks: {
            onSend: (message: string) => void;
            onOpen: () => void;
            onClose: () => void;
        },
    ) {
        this.onSend = callbacks.onSend;
        this.onOpen = callbacks.onOpen;
        this.onClose = callbacks.onClose;

        // Create style element
        this.styleElement = document.createElement('style');
        this.styleElement.textContent = generateStyles(appearance);

        // Create main container
        this.container = document.createElement('div');
        this.container.className = 'sapiensly-widget-container';
        this.container.id = 'sapiensly-widget';

        // Create bubble
        this.bubble = new Bubble(() => this.open());

        // Create chat window
        this.window = this.createWindow(appearance);

        // Create messages component
        this.messages = new Messages(appearance.welcome_message);

        // Create input component
        this.input = new Input(appearance.placeholder_text, this.onSend);

        // Assemble the window
        const messagesContainer = this.window.querySelector(
            '.sapiensly-window-body',
        );
        if (messagesContainer) {
            messagesContainer.appendChild(this.messages.getElement());
            messagesContainer.appendChild(this.input.getElement());

            if (behavior.show_powered_by) {
                const powered = document.createElement('div');
                powered.className = 'sapiensly-powered';
                powered.innerHTML =
                    'Powered by <a href="https://sapiensly.com" target="_blank" rel="noopener">Sapiensly</a>';
                messagesContainer.appendChild(powered);
            }
        }
    }

    private createWindow(appearance: AppearanceConfig): HTMLDivElement {
        const window = document.createElement('div');
        window.className = 'sapiensly-window';

        // Header
        const header = document.createElement('div');
        header.className = 'sapiensly-header';

        const title = document.createElement('div');
        title.className = 'sapiensly-header-title';
        title.textContent = appearance.widget_title;

        const closeBtn = document.createElement('button');
        closeBtn.className = 'sapiensly-header-close';
        closeBtn.innerHTML = icons.close;
        closeBtn.setAttribute('aria-label', 'Close chat');
        closeBtn.addEventListener('click', () => this.close());

        header.appendChild(title);
        header.appendChild(closeBtn);

        // Body
        const body = document.createElement('div');
        body.className = 'sapiensly-window-body';
        body.style.display = 'flex';
        body.style.flexDirection = 'column';
        body.style.flex = '1';
        body.style.overflow = 'hidden';

        window.appendChild(header);
        window.appendChild(body);

        return window;
    }

    /**
     * Mount the widget to the DOM.
     */
    mount(): void {
        document.head.appendChild(this.styleElement);
        document.body.appendChild(this.container);
        this.bubble.mount(this.container);
        this.container.appendChild(this.window);
    }

    /**
     * Open the chat window.
     */
    open(): void {
        if (this.isOpen) return;

        this.isOpen = true;
        this.window.classList.add('open');
        this.bubble.hide();
        this.input.focus();
        this.onOpen();
    }

    /**
     * Close the chat window.
     */
    close(): void {
        if (!this.isOpen) return;

        this.isOpen = false;
        this.window.classList.remove('open');
        this.bubble.show();
        this.onClose();
    }

    /**
     * Toggle the chat window.
     */
    toggle(): void {
        if (this.isOpen) {
            this.close();
        } else {
            this.open();
        }
    }

    /**
     * Add a message to the chat.
     */
    addMessage(message: Message): void {
        this.messages.addMessage(message);
    }

    /**
     * Update a message (for streaming).
     */
    updateMessage(messageId: string, content: string): void {
        this.messages.updateMessage(messageId, content);
    }

    /**
     * Show typing indicator.
     */
    showTyping(): void {
        this.messages.showTyping();
    }

    /**
     * Hide typing indicator.
     */
    hideTyping(): void {
        this.messages.hideTyping();
    }

    /**
     * Append a raw element to the messages area.
     */
    appendToMessages(element: HTMLElement): void {
        this.messages.appendElement(element);
    }

    /**
     * Scroll messages to the bottom.
     */
    scrollToBottom(): void {
        this.messages.scrollToBottom();
    }

    /**
     * Disable input.
     */
    disableInput(): void {
        this.input.disable();
    }

    /**
     * Enable input.
     */
    enableInput(): void {
        this.input.enable();
    }

    /**
     * Destroy the widget.
     */
    destroy(): void {
        this.bubble.destroy();
        this.styleElement.remove();
        this.container.remove();
    }

    /**
     * Check if window is open.
     */
    isWindowOpen(): boolean {
        return this.isOpen;
    }
}
