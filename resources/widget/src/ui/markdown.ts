import { marked } from 'marked';
import hljs from 'highlight.js/lib/core';

// Import common languages (keeps bundle smaller than importing all)
import javascript from 'highlight.js/lib/languages/javascript';
import typescript from 'highlight.js/lib/languages/typescript';
import python from 'highlight.js/lib/languages/python';
import php from 'highlight.js/lib/languages/php';
import sql from 'highlight.js/lib/languages/sql';
import json from 'highlight.js/lib/languages/json';
import bash from 'highlight.js/lib/languages/bash';
import css from 'highlight.js/lib/languages/css';
import xml from 'highlight.js/lib/languages/xml';
import markdown from 'highlight.js/lib/languages/markdown';

// Register languages
hljs.registerLanguage('javascript', javascript);
hljs.registerLanguage('js', javascript);
hljs.registerLanguage('typescript', typescript);
hljs.registerLanguage('ts', typescript);
hljs.registerLanguage('python', python);
hljs.registerLanguage('py', python);
hljs.registerLanguage('php', php);
hljs.registerLanguage('sql', sql);
hljs.registerLanguage('json', json);
hljs.registerLanguage('bash', bash);
hljs.registerLanguage('sh', bash);
hljs.registerLanguage('shell', bash);
hljs.registerLanguage('css', css);
hljs.registerLanguage('html', xml);
hljs.registerLanguage('xml', xml);
hljs.registerLanguage('markdown', markdown);
hljs.registerLanguage('md', markdown);

// Configure marked with syntax highlighting
marked.setOptions({
    breaks: true,
    gfm: true,
});

// Custom renderer for code blocks with syntax highlighting
const renderer = new marked.Renderer();

renderer.code = function ({ text, lang }: { text: string; lang?: string }) {
    const language = lang && hljs.getLanguage(lang) ? lang : 'plaintext';
    let highlighted: string;

    try {
        if (language === 'plaintext') {
            highlighted = escapeHtml(text);
        } else {
            highlighted = hljs.highlight(text, { language }).value;
        }
    } catch {
        highlighted = escapeHtml(text);
    }

    return `<pre><code class="hljs language-${language}">${highlighted}</code></pre>`;
};

marked.use({ renderer });

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
