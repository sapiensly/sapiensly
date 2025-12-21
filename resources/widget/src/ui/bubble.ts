import { icons } from './icons';

/**
 * Chat bubble button that opens the widget.
 */
export class Bubble {
    private element: HTMLButtonElement;
    private onClick: () => void;

    constructor(onClick: () => void) {
        this.onClick = onClick;
        this.element = this.createElement();
    }

    private createElement(): HTMLButtonElement {
        const button = document.createElement('button');
        button.className = 'sapiensly-bubble';
        button.innerHTML = icons.chat;
        button.setAttribute('aria-label', 'Open chat');
        button.addEventListener('click', this.onClick);
        return button;
    }

    /**
     * Mount the bubble to the DOM.
     */
    mount(container: HTMLElement): void {
        container.appendChild(this.element);
    }

    /**
     * Show the bubble.
     */
    show(): void {
        this.element.style.display = 'flex';
    }

    /**
     * Hide the bubble.
     */
    hide(): void {
        this.element.style.display = 'none';
    }

    /**
     * Destroy the bubble.
     */
    destroy(): void {
        this.element.removeEventListener('click', this.onClick);
        this.element.remove();
    }
}
