import { marked } from 'marked';

// Configure marked for safe rendering
marked.setOptions({
    breaks: true,
    gfm: true,
});

/**
 * Parse markdown content to HTML.
 * Only used for assistant messages.
 */
export function parseMarkdown(content: string): string {
    try {
        return marked.parse(content || '') as string;
    } catch {
        return escapeHtml(content);
    }
}

/**
 * Escape HTML special characters for safe display.
 */
export function escapeHtml(text: string): string {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
