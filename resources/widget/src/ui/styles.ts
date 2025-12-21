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
            white-space: pre-wrap;
        }

        .sapiensly-message-user {
            background: var(--sw-primary);
            color: white;
            align-self: flex-end;
            border-bottom-right-radius: 4px;
        }

        .sapiensly-message-assistant {
            background: rgba(0, 0, 0, 0.08);
            color: var(--sw-text);
            align-self: flex-start;
            border-bottom-left-radius: 4px;
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
