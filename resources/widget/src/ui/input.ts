import type { Attachment } from '../types';
import { icons } from './icons';

const ACCEPT =
    '.jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.md,.csv,.json,.docx,image/*,application/pdf';

/**
 * Input area component: text field, file-attach button, and a row of chips for
 * files staged for the next message.
 */
export class Input {
    private element: HTMLDivElement;
    private input: HTMLInputElement;
    private button: HTMLButtonElement;
    private attachButton: HTMLButtonElement;
    private fileInput: HTMLInputElement;
    private chips: HTMLDivElement;
    private onSend: (message: string, attachments: Attachment[]) => void;
    private onUpload: (file: File) => Promise<Attachment>;
    private pending: Attachment[] = [];
    private disabled = false;

    constructor(
        placeholder: string,
        onSend: (message: string, attachments: Attachment[]) => void,
        onUpload: (file: File) => Promise<Attachment>,
    ) {
        this.onSend = onSend;
        this.onUpload = onUpload;
        this.element = this.createElement(placeholder);
        this.input = this.element.querySelector(
            '.sapiensly-input',
        ) as HTMLInputElement;
        this.button = this.element.querySelector(
            '.sapiensly-send',
        ) as HTMLButtonElement;
        this.attachButton = this.element.querySelector(
            '.sapiensly-attach',
        ) as HTMLButtonElement;
        this.fileInput = this.element.querySelector(
            '.sapiensly-file-input',
        ) as HTMLInputElement;
        this.chips = this.element.querySelector(
            '.sapiensly-attachments',
        ) as HTMLDivElement;
    }

    private createElement(placeholder: string): HTMLDivElement {
        const container = document.createElement('div');
        container.className = 'sapiensly-input-area';

        const chips = document.createElement('div');
        chips.className = 'sapiensly-attachments';

        const row = document.createElement('div');
        row.className = 'sapiensly-input-row';

        const attach = document.createElement('button');
        attach.className = 'sapiensly-attach';
        attach.type = 'button';
        attach.innerHTML = icons.attach;
        attach.setAttribute('aria-label', 'Attach a file');

        const fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.className = 'sapiensly-file-input';
        fileInput.accept = ACCEPT;
        fileInput.multiple = true;
        fileInput.style.display = 'none';

        attach.addEventListener('click', () => {
            if (!this.disabled) this.fileInput.click();
        });
        fileInput.addEventListener('change', this.handleFiles.bind(this));

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'sapiensly-input';
        input.placeholder = placeholder;
        input.addEventListener('keydown', this.handleKeyDown.bind(this));

        const button = document.createElement('button');
        button.className = 'sapiensly-send';
        button.innerHTML = icons.send;
        button.setAttribute('aria-label', 'Send message');
        button.addEventListener('click', () => this.handleSend());

        row.appendChild(attach);
        row.appendChild(fileInput);
        row.appendChild(input);
        row.appendChild(button);

        container.appendChild(chips);
        container.appendChild(row);

        return container;
    }

    private async handleFiles(event: Event): Promise<void> {
        const target = event.target as HTMLInputElement;
        const files = Array.from(target.files ?? []);
        target.value = '';

        for (const file of files) {
            const chip = this.addChip(file.name, true);
            try {
                const attachment = await this.onUpload(file);
                this.pending.push(attachment);
                chip.dataset.attachmentId = attachment.id;
                chip.classList.remove('sapiensly-attachment-loading');
            } catch {
                chip.classList.add('sapiensly-attachment-error');
                chip.classList.remove('sapiensly-attachment-loading');
                setTimeout(() => chip.remove(), 2500);
            }
        }
    }

    private addChip(name: string, loading: boolean): HTMLDivElement {
        const chip = document.createElement('div');
        chip.className =
            'sapiensly-attachment' +
            (loading ? ' sapiensly-attachment-loading' : '');

        const label = document.createElement('span');
        label.className = 'sapiensly-attachment-name';
        label.textContent = name;

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'sapiensly-attachment-remove';
        remove.innerHTML = icons.close;
        remove.setAttribute('aria-label', 'Remove attachment');
        remove.addEventListener('click', () => {
            const id = chip.dataset.attachmentId;
            if (id) {
                this.pending = this.pending.filter((a) => a.id !== id);
            }
            chip.remove();
        });

        chip.appendChild(label);
        chip.appendChild(remove);
        this.chips.appendChild(chip);

        return chip;
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

        // A message needs text, file(s), or both.
        if (!message && this.pending.length === 0) return;

        this.onSend(message, [...this.pending]);
        this.input.value = '';
        this.pending = [];
        this.chips.innerHTML = '';
    }

    /**
     * Disable the input.
     */
    disable(): void {
        this.disabled = true;
        this.input.disabled = true;
        this.button.disabled = true;
        this.attachButton.disabled = true;
    }

    /**
     * Enable the input.
     */
    enable(): void {
        this.disabled = false;
        this.input.disabled = false;
        this.button.disabled = false;
        this.attachButton.disabled = false;
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
