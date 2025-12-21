import { icons } from './icons';

/**
 * Input area component.
 */
export class Input {
    private element: HTMLDivElement;
    private input: HTMLInputElement;
    private button: HTMLButtonElement;
    private onSend: (message: string) => void;
    private disabled = false;

    constructor(placeholder: string, onSend: (message: string) => void) {
        this.onSend = onSend;
        this.element = this.createElement(placeholder);
        this.input = this.element.querySelector('.sapiensly-input') as HTMLInputElement;
        this.button = this.element.querySelector('.sapiensly-send') as HTMLButtonElement;
    }

    private createElement(placeholder: string): HTMLDivElement {
        const container = document.createElement('div');
        container.className = 'sapiensly-input-area';

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'sapiensly-input';
        input.placeholder = placeholder;
        input.addEventListener('keydown', this.handleKeyDown.bind(this));

        const button = document.createElement('button');
        button.className = 'sapiensly-send';
        button.innerHTML = icons.send;
        button.setAttribute('aria-label', 'Send message');
        button.addEventListener('click', this.handleSend.bind(this));

        container.appendChild(input);
        container.appendChild(button);

        return container;
    }

    private handleKeyDown(event: KeyboardEvent): void {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            this.handleSend();
        }
    }

    private handleSend(): void {
        if (this.disabled) return;

        const message = this.input.value.trim();
        if (message) {
            this.onSend(message);
            this.input.value = '';
        }
    }

    /**
     * Disable the input.
     */
    disable(): void {
        this.disabled = true;
        this.input.disabled = true;
        this.button.disabled = true;
    }

    /**
     * Enable the input.
     */
    enable(): void {
        this.disabled = false;
        this.input.disabled = false;
        this.button.disabled = false;
        this.input.focus();
    }

    /**
     * Focus the input.
     */
    focus(): void {
        this.input.focus();
    }

    /**
     * Get the DOM element.
     */
    getElement(): HTMLDivElement {
        return this.element;
    }
}
