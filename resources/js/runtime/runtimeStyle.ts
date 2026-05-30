/**
 * Maps manifest `settings` (accent colour + font family) to an inline style
 * object applied to the runtime page surface, so the whole tree inherits the
 * brand accent (--sp-accent, used by buttons/links/highlights) and font.
 * Shared by the runtime page and the Builder preview.
 */
const FONT_STACKS: Record<string, string> = {
    sans: 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif',
    serif: 'ui-serif, Georgia, Cambria, "Times New Roman", serif',
    rounded: '"SF Pro Rounded", ui-rounded, "Hiragino Maru Gothic ProN", "Quicksand", system-ui, sans-serif',
    mono: 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
};

export function runtimeSettingsStyle(settings: { accent?: string; font?: string } | null | undefined): Record<string, string> {
    const out: Record<string, string> = {};
    if (settings?.accent) out['--sp-accent'] = settings.accent;
    if (settings?.font && settings.font !== 'sans' && FONT_STACKS[settings.font]) {
        out.fontFamily = FONT_STACKS[settings.font];
    }
    return out;
}
