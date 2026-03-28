import type { AppearanceConfig } from '../types';

/**
 * Generate CSS styles for the widget.
 * All styles are scoped to the widget container to avoid conflicts.
 */
export function generateStyles(config: AppearanceConfig): string {
    return `
        .sapiensly-widget-container {
            --sw-primary: ${config.primary_color};
            --sw-bg: ${config.background_color};
            --sw-text: ${config.text_color};
            --sw-shadow: rgba(0, 0, 0, 0.15);

            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            font-size: 14px;
            line-height: 1.5;
            color: var(--sw-text);
            box-sizing: border-box;
        }

        .sapiensly-widget-container *,
        .sapiensly-widget-container *::before,
        .sapiensly-widget-container *::after {
            box-sizing: border-box;
        }

        /* Chat Bubble */
        .sapiensly-bubble {
            position: fixed;
            bottom: 20px;
            ${config.position === 'bottom-right' ? 'right: 20px;' : 'left: 20px;'}
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--sw-primary);
            box-shadow: 0 4px 12px var(--sw-shadow);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            z-index: 999998;
            border: none;
            outline: none;
        }

        .sapiensly-bubble:hover {
            transform: scale(1.05);
            box-shadow: 0 6px 16px var(--sw-shadow);
        }

        .sapiensly-bubble svg {
            width: 28px;
            height: 28px;
            fill: white;
        }

        /* Chat Window */
        .sapiensly-window {
            position: fixed;
            bottom: 100px;
            ${config.position === 'bottom-right' ? 'right: 20px;' : 'left: 20px;'}
            width: 380px;
            height: 520px;
            max-height: calc(100vh - 140px);
            background: var(--sw-bg);
            border-radius: 16px;
            box-shadow: 0 8px 32px var(--sw-shadow);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            z-index: 999999;
            opacity: 0;
            transform: translateY(20px) scale(0.95);
            transition: opacity 0.2s ease, transform 0.2s ease;
            pointer-events: none;
        }

        .sapiensly-window.open {
            opacity: 1;
            transform: translateY(0) scale(1);
            pointer-events: auto;
        }

        /* Header */
        .sapiensly-header {
            padding: 16px;
            background: var(--sw-primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }

        .sapiensly-header-title {
            font-weight: 600;
            font-size: 16px;
        }

        .sapiensly-header-close {
            background: transparent;
            border: none;
            cursor: pointer;
            padding: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            transition: background 0.2s;
        }

        .sapiensly-header-close:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .sapiensly-header-close svg {
            width: 20px;
            height: 20px;
            fill: white;
        }

        /* Messages Container */
        .sapiensly-messages {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        /* Welcome Message */
        .sapiensly-welcome {
            background: rgba(0, 0, 0, 0.05);
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 8px;
        }

        /* Message Bubble */
        .sapiensly-message {
            max-width: 85%;
            padding: 10px 14px;
            border-radius: 16px;
            word-wrap: break-word;
        }

        .sapiensly-message-user {
            background: var(--sw-primary);
            color: white;
            align-self: flex-end;
            border-bottom-right-radius: 4px;
            white-space: pre-wrap;
        }

        .sapiensly-message-assistant {
            background: rgba(0, 0, 0, 0.08);
            color: var(--sw-text);
            align-self: flex-start;
            border-bottom-left-radius: 4px;
        }

        /* Markdown styles for assistant messages */
        .sapiensly-message-assistant p {
            margin: 0 0 0.5em 0;
        }

        .sapiensly-message-assistant p:last-child {
            margin-bottom: 0;
        }

        .sapiensly-message-assistant ul,
        .sapiensly-message-assistant ol {
            margin: 0.5em 0;
            padding-left: 1.5em;
        }

        .sapiensly-message-assistant li {
            margin: 0.25em 0;
        }

        .sapiensly-message-assistant code {
            background: rgba(0, 0, 0, 0.1);
            padding: 0.15em 0.4em;
            border-radius: 4px;
            font-size: 0.9em;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }

        .sapiensly-message-assistant pre {
            background: #1e1e1e;
            padding: 0.75em 1em;
            border-radius: 8px;
            overflow-x: auto;
            margin: 0.5em 0;
        }

        .sapiensly-message-assistant pre code {
            background: none;
            padding: 0;
            font-size: 0.85em;
            color: #d4d4d4;
        }

        /* Syntax highlighting - VS Code Dark+ inspired */
        .sapiensly-message-assistant .hljs-keyword,
        .sapiensly-message-assistant .hljs-selector-tag,
        .sapiensly-message-assistant .hljs-built_in,
        .sapiensly-message-assistant .hljs-name {
            color: #569cd6;
        }

        .sapiensly-message-assistant .hljs-string,
        .sapiensly-message-assistant .hljs-attr {
            color: #ce9178;
        }

        .sapiensly-message-assistant .hljs-number,
        .sapiensly-message-assistant .hljs-literal {
            color: #b5cea8;
        }

        .sapiensly-message-assistant .hljs-function,
        .sapiensly-message-assistant .hljs-title {
            color: #dcdcaa;
        }

        .sapiensly-message-assistant .hljs-comment {
            color: #6a9955;
            font-style: italic;
        }

        .sapiensly-message-assistant .hljs-variable,
        .sapiensly-message-assistant .hljs-params {
            color: #9cdcfe;
        }

        .sapiensly-message-assistant .hljs-class,
        .sapiensly-message-assistant .hljs-type {
            color: #4ec9b0;
        }

        .sapiensly-message-assistant .hljs-property {
            color: #9cdcfe;
        }

        .sapiensly-message-assistant .hljs-operator {
            color: #d4d4d4;
        }

        .sapiensly-message-assistant .hljs-punctuation {
            color: #d4d4d4;
        }

        .sapiensly-message-assistant .hljs-meta {
            color: #c586c0;
        }

        .sapiensly-message-assistant .hljs-regexp {
            color: #d16969;
        }

        .sapiensly-message-assistant .hljs-tag {
            color: #569cd6;
        }

        .sapiensly-message-assistant .hljs-selector-class,
        .sapiensly-message-assistant .hljs-selector-id {
            color: #d7ba7d;
        }

        .sapiensly-message-assistant .hljs-attribute {
            color: #9cdcfe;
        }

        .sapiensly-message-assistant a {
            color: var(--sw-primary);
            text-decoration: underline;
        }

        .sapiensly-message-assistant a:hover {
            opacity: 0.8;
        }

        .sapiensly-message-assistant strong {
            font-weight: 600;
        }

        .sapiensly-message-assistant em {
            font-style: italic;
        }

        .sapiensly-message-assistant blockquote {
            border-left: 3px solid var(--sw-primary);
            margin: 0.5em 0;
            padding-left: 1em;
            opacity: 0.9;
        }

        .sapiensly-message-assistant h1,
        .sapiensly-message-assistant h2,
        .sapiensly-message-assistant h3,
        .sapiensly-message-assistant h4 {
            margin: 0.75em 0 0.5em 0;
            font-weight: 600;
        }

        .sapiensly-message-assistant h1:first-child,
        .sapiensly-message-assistant h2:first-child,
        .sapiensly-message-assistant h3:first-child,
        .sapiensly-message-assistant h4:first-child {
            margin-top: 0;
        }

        .sapiensly-message-assistant h1 { font-size: 1.3em; }
        .sapiensly-message-assistant h2 { font-size: 1.2em; }
        .sapiensly-message-assistant h3 { font-size: 1.1em; }
        .sapiensly-message-assistant h4 { font-size: 1em; }

        .sapiensly-message-assistant hr {
            border: none;
            border-top: 1px solid rgba(0, 0, 0, 0.15);
            margin: 0.75em 0;
        }

        .sapiensly-message-assistant table {
            border-collapse: collapse;
            margin: 0.5em 0;
            font-size: 0.9em;
        }

        .sapiensly-message-assistant th,
        .sapiensly-message-assistant td {
            border: 1px solid rgba(0, 0, 0, 0.15);
            padding: 0.4em 0.6em;
        }

        .sapiensly-message-assistant th {
            background: rgba(0, 0, 0, 0.05);
            font-weight: 600;
        }

        /* Typing Indicator */
        .sapiensly-typing {
            display: flex;
            gap: 4px;
            padding: 12px 16px;
            background: rgba(0, 0, 0, 0.08);
            border-radius: 16px;
            align-self: flex-start;
            border-bottom-left-radius: 4px;
        }

        .sapiensly-typing-dot {
            width: 8px;
            height: 8px;
            background: var(--sw-text);
            opacity: 0.4;
            border-radius: 50%;
            animation: sapiensly-bounce 1.4s infinite ease-in-out;
        }

        .sapiensly-typing-dot:nth-child(1) { animation-delay: 0s; }
        .sapiensly-typing-dot:nth-child(2) { animation-delay: 0.2s; }
        .sapiensly-typing-dot:nth-child(3) { animation-delay: 0.4s; }

        @keyframes sapiensly-bounce {
            0%, 60%, 100% { transform: translateY(0); }
            30% { transform: translateY(-4px); }
        }

        /* Input Area */
        .sapiensly-input-area {
            padding: 12px 16px;
            border-top: 1px solid rgba(0, 0, 0, 0.1);
            display: flex;
            gap: 8px;
            flex-shrink: 0;
        }

        .sapiensly-input {
            flex: 1;
            padding: 10px 14px;
            border: 1px solid rgba(0, 0, 0, 0.15);
            border-radius: 20px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
            background: white;
            color: var(--sw-text);
        }

        .sapiensly-input:focus {
            border-color: var(--sw-primary);
        }

        .sapiensly-input::placeholder {
            color: rgba(0, 0, 0, 0.4);
        }

        .sapiensly-send {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--sw-primary);
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.2s, opacity 0.2s;
            flex-shrink: 0;
        }

        .sapiensly-send:hover {
            transform: scale(1.05);
        }

        .sapiensly-send:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .sapiensly-send svg {
            width: 18px;
            height: 18px;
            fill: white;
        }

        /* Flow Menu */
        .sapiensly-flow-menu {
            display: flex;
            flex-direction: column;
            gap: 8px;
            padding: 4px 0;
            align-self: flex-start;
            max-width: 85%;
        }

        .sapiensly-flow-menu-message {
            background: rgba(0, 0, 0, 0.08);
            padding: 10px 14px;
            border-radius: 16px;
            border-bottom-left-radius: 4px;
            font-size: 14px;
            line-height: 1.4;
        }

        .sapiensly-flow-menu-options {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .sapiensly-flow-option {
            padding: 8px 16px;
            border: 1.5px solid var(--sw-primary);
            border-radius: 20px;
            background: transparent;
            color: var(--sw-primary);
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            font-family: inherit;
        }

        .sapiensly-flow-option:hover {
            background: var(--sw-primary);
            color: white;
        }

        .sapiensly-flow-option-selected {
            background: var(--sw-primary) !important;
            color: white !important;
            border-color: var(--sw-primary) !important;
        }

        .sapiensly-flow-option-disabled {
            opacity: 0.4;
            cursor: not-allowed;
            pointer-events: none;
        }

        .sapiensly-flow-option-disabled:hover {
            background: transparent;
            color: var(--sw-primary);
        }

        /* Powered By */
        .sapiensly-powered {
            padding: 8px;
            text-align: center;
            font-size: 11px;
            color: rgba(0, 0, 0, 0.4);
            border-top: 1px solid rgba(0, 0, 0, 0.05);
        }

        .sapiensly-powered a {
            color: inherit;
            text-decoration: none;
        }

        .sapiensly-powered a:hover {
            text-decoration: underline;
        }

        /* Mobile Responsive */
        @media (max-width: 480px) {
            .sapiensly-window {
                width: calc(100vw - 20px);
                height: calc(100vh - 100px);
                max-height: none;
                bottom: 80px;
                ${config.position === 'bottom-right' ? 'right: 10px;' : 'left: 10px;'}
                border-radius: 12px;
            }

            .sapiensly-bubble {
                width: 54px;
                height: 54px;
                bottom: 16px;
                ${config.position === 'bottom-right' ? 'right: 16px;' : 'left: 16px;'}
            }
        }
    `;
}
