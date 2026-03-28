import type { FlowMenuOption } from '../types';

/**
 * Renders interactive flow menu buttons in the chat.
 */
export class FlowMenu {
    private element: HTMLDivElement;
    private disabled = false;

    constructor(
        message: string,
        options: FlowMenuOption[],
        private onSelect: (optionId: string, label: string) => void,
    ) {
        this.element = this.createElement(message, options);
    }

    private createElement(
        message: string,
        options: FlowMenuOption[],
    ): HTMLDivElement {
        const wrapper = document.createElement('div');
        wrapper.className = 'sapiensly-flow-menu';

        if (message) {
            const messageEl = document.createElement('div');
            messageEl.className = 'sapiensly-flow-menu-message';
            messageEl.textContent = message;
            wrapper.appendChild(messageEl);
        }

        const optionsContainer = document.createElement('div');
        optionsContainer.className = 'sapiensly-flow-menu-options';

        options.forEach((option) => {
            const btn = document.createElement('button');
            btn.className = 'sapiensly-flow-option';
            btn.textContent = option.label;
            btn.type = 'button';

            btn.addEventListener('click', () => {
                if (this.disabled) return;
                this.selectOption(option, btn);
            });

            optionsContainer.appendChild(btn);
        });

        wrapper.appendChild(optionsContainer);
        return wrapper;
    }

    private selectOption(option: FlowMenuOption, btn: HTMLButtonElement): void {
        this.disabled = true;

        // Highlight selected, disable all
        const buttons = this.element.querySelectorAll('.sapiensly-flow-option');
        buttons.forEach((b) => {
            (b as HTMLButtonElement).disabled = true;
            b.classList.add('sapiensly-flow-option-disabled');
        });
        btn.classList.remove('sapiensly-flow-option-disabled');
        btn.classList.add('sapiensly-flow-option-selected');

        this.onSelect(option.id, option.label);
    }

    getElement(): HTMLDivElement {
        return this.element;
    }

    disable(): void {
        this.disabled = true;
        const buttons = this.element.querySelectorAll('.sapiensly-flow-option');
        buttons.forEach((b) => {
            (b as HTMLButtonElement).disabled = true;
            b.classList.add('sapiensly-flow-option-disabled');
        });
    }
}
