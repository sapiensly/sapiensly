/**
 * Slash-command registry for the Builder composer.
 *
 * Each command is a UX wrapper over the chat: typing `/seed clientes 50`
 * opens a menu, and on submit we expand the input to a longer, well-formed
 * prompt that the existing Builder tools can act on. There is no special
 * backend route — the expanded prompt goes through the normal
 * `sendMessage` endpoint, same as a hand-typed message.
 *
 * Locale: all descriptions and prompts live in i18n keys. The expansion
 * happens against whatever locale dict the caller passes in, so the user
 * sees (and Claude receives) the prompt in the conversation's language —
 * not necessarily the UI locale.
 */

export interface SlashCommandContext {
    /** Page currently visible in the preview pane; used by /explain. */
    currentPage: string;
}

export interface SlashCommand {
    /** Stable id used for i18n keys + parser matching. */
    id: 'seed' | 'clean' | 'duplicate_page' | 'explain' | 'translate';
    /** Slash-prefixed display name. Can include spaces (e.g. `duplicate page`). */
    name: string;
    /** How many positional args the command needs to be considered "complete". */
    minArgs: number;
}

/**
 * Order here drives the menu order — most-useful-first. The parser tries
 * names in DESCENDING length so multi-word names like `duplicate page`
 * match before any hypothetical `duplicate` short form.
 */
export const SLASH_COMMANDS: SlashCommand[] = [
    { id: 'seed', name: 'seed', minArgs: 2 },
    { id: 'clean', name: 'clean', minArgs: 0 },
    { id: 'duplicate_page', name: 'duplicate page', minArgs: 1 },
    { id: 'explain', name: 'explain', minArgs: 0 },
    { id: 'translate', name: 'translate', minArgs: 1 },
];

/** Anything after the slash, lowercased, used for filtering the menu. */
export function slashFilterFor(input: string): string | null {
    if (!input.startsWith('/')) return null;
    return input.slice(1).toLowerCase();
}

/**
 * Filter the registry by what the user typed after `/`. We use a forgiving
 * "starts-with" against the command name AND its first word — typing
 * `/dup` should still surface `/duplicate page`.
 */
export function matchingCommands(filter: string): SlashCommand[] {
    const f = filter.trim();
    if (f === '') return SLASH_COMMANDS;
    return SLASH_COMMANDS.filter((cmd) => {
        const name = cmd.name.toLowerCase();
        return name.startsWith(f) || name.split(' ')[0].startsWith(f);
    });
}

export interface ParsedSlashInput {
    command: SlashCommand;
    /** Trimmed arg string. Splitting is command-specific, so we don't split here. */
    rawArgs: string;
}

/**
 * Find the command that matches the input. Multi-word names take
 * precedence — sorted descending by name length so `/duplicate page foo`
 * binds to `duplicate page`, not to some shorter prefix.
 */
export function parseSlashInput(input: string): ParsedSlashInput | null {
    if (!input.startsWith('/')) return null;
    const body = input.slice(1);
    const sorted = [...SLASH_COMMANDS].sort((a, b) => b.name.length - a.name.length);
    for (const cmd of sorted) {
        if (body === cmd.name || body.toLowerCase().startsWith(cmd.name + ' ')) {
            const rawArgs = body.slice(cmd.name.length).trim();
            return { command: cmd, rawArgs };
        }
    }
    return null;
}

/**
 * Expand a parsed slash command into the full prompt that goes to Claude.
 * `lookup` is the i18n resolver (we pass it in instead of importing
 * vue-i18n so this module stays framework-agnostic and easy to test).
 *
 * Returns null when the command needs args and they're missing — callers
 * can short-circuit and tell the user to type them in.
 */
export function expandSlashCommand(
    parsed: ParsedSlashInput,
    ctx: SlashCommandContext,
    lookup: (key: string) => string,
): string | null {
    const { command, rawArgs } = parsed;
    const promptTemplate = lookup(`apps.builder.slash.${command.id}.prompt`);

    switch (command.id) {
        case 'seed': {
            // Expect `<object> <count>`. Be forgiving: the count can come
            // before the object name ("50 clientes"), with either order
            // intended. We pick whichever token parses as a positive int.
            const tokens = rawArgs.split(/\s+/).filter((t) => t !== '');
            if (tokens.length < 2) return null;
            const countIdx = tokens.findIndex((t) => /^\d+$/.test(t));
            if (countIdx === -1) return null;
            const count = tokens[countIdx];
            const object = tokens
                .filter((_, i) => i !== countIdx)
                .join(' ')
                .trim();
            if (object === '') return null;
            return promptTemplate
                .replace('{count}', count)
                .replace('{object}', object);
        }
        case 'clean':
            return promptTemplate;
        case 'duplicate_page': {
            if (rawArgs === '') return null;
            return promptTemplate.replace('{page}', rawArgs);
        }
        case 'explain': {
            const page = ctx.currentPage || '(página actual)';
            return promptTemplate.replace('{currentPage}', page);
        }
        case 'translate': {
            if (rawArgs === '') return null;
            return promptTemplate.replace('{language}', rawArgs);
        }
    }
    return null;
}
