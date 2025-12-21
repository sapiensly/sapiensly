import type { VisitorInfo, WidgetEventCallback, WidgetEventType } from './types';
import { Widget } from './widget';

/**
 * Global command queue type.
 */
type CommandArgs =
    | ['init', string]
    | ['open']
    | ['close']
    | ['toggle']
    | ['identify', VisitorInfo]
    | ['on', WidgetEventType, WidgetEventCallback]
    | ['destroy'];

/**
 * Global sapiensly function interface.
 */
interface SapienslyFunction {
    (...args: CommandArgs): void;
    q?: CommandArgs[];
}

// Widget instance
let widget: Widget | null = null;

/**
 * Process a command.
 */
function processCommand(args: CommandArgs): void {
    const [command, ...params] = args;

    switch (command) {
        case 'init':
            if (widget) {
                console.warn('[Sapiensly] Widget already initialized');
                return;
            }
            widget = new Widget({ token: params[0] as string });
            widget.init().catch((error) => {
                console.error('[Sapiensly] Initialization failed:', error);
            });
            break;

        case 'open':
            widget?.open();
            break;

        case 'close':
            widget?.close();
            break;

        case 'toggle':
            widget?.toggle();
            break;

        case 'identify':
            widget?.identify(params[0] as VisitorInfo);
            break;

        case 'on':
            widget?.on(
                params[0] as WidgetEventType,
                params[1] as WidgetEventCallback
            );
            break;

        case 'destroy':
            widget?.destroy();
            widget = null;
            break;

        default:
            console.warn(`[Sapiensly] Unknown command: ${command}`);
    }
}

/**
 * Main entry point.
 *
 * This creates the global `sapiensly` function that can be called
 * to control the widget.
 */
function main(): void {
    // Get existing queue
    const existingQueue = (window as unknown as { sapiensly?: SapienslyFunction }).sapiensly?.q || [];

    // Create the main function
    const sapiensly: SapienslyFunction = (...args: CommandArgs) => {
        processCommand(args);
    };

    // Replace the stub with the real function
    (window as unknown as { sapiensly: SapienslyFunction }).sapiensly = sapiensly;

    // Process any queued commands
    for (const args of existingQueue) {
        processCommand(args);
    }
}

// Run on load
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', main);
} else {
    main();
}

// Export for direct module usage
export { Widget };
export type { VisitorInfo, WidgetEventCallback, WidgetEventType };
