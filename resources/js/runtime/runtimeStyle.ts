/**
 * Maps manifest `settings` (accent colour + font family) to an inline style
 * object applied to the runtime page surface, so the whole tree inherits the
 * brand accent (--sp-accent, used by buttons/links/highlights) and font.
 * Shared by the runtime page and the Builder preview.
 */
const FONT_STACKS: Record<string, string> = {
    sans: 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif',
    serif: 'ui-serif, Georgia, Cambria, "Times New Roman", serif',
    rounded:
        '"SF Pro Rounded", ui-rounded, "Hiragino Maru Gothic ProN", "Quicksand", system-ui, sans-serif',
    mono: 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
};

/** Darken a #RRGGBB hex by `amount` (0-1) for a button hover/active shade. */
function darken(hex: string, amount: number): string {
    const m = /^#([0-9a-fA-F]{6})$/.exec(hex);
    if (!m) return hex;
    const n = parseInt(m[1], 16);
    const f = Math.max(0, 1 - amount);
    const r = Math.round(((n >> 16) & 0xff) * f);
    const g = Math.round(((n >> 8) & 0xff) * f);
    const b = Math.round((n & 0xff) * f);
    return '#' + [r, g, b].map((c) => c.toString(16).padStart(2, '0')).join('');
}

export interface Palette {
    ramp?: Record<string, string>;
    soft?: string;
    contrast?: string;
    chart?: string[];
}

export function runtimeSettingsStyle(
    settings:
        | { accent?: string; font?: string; palette?: Palette }
        | null
        | undefined,
): Record<string, string> {
    const out: Record<string, string> = {};
    if (settings?.accent) {
        // Point both the generic accent AND the button/link accent
        // (--sp-accent-blue, which `bg-accent-blue` resolves) at the brand
        // colour, scoped to the app surface — so primary buttons, links and
        // highlights all adopt it.
        out['--sp-accent'] = settings.accent;
        out['--sp-accent-blue'] = settings.accent;
        out['--sp-accent-blue-hover'] = darken(settings.accent, 0.12);
    }
    // The server-derived professional palette → CSS vars the whole tree (blocks,
    // charts, custom_css) can use: a tint/shade ramp, a soft surface tint, an
    // on-accent contrast colour, and a cohesive categorical chart series.
    const p = settings?.palette;
    if (p) {
        for (const [stop, hex] of Object.entries(p.ramp ?? {})) {
            out[`--sp-accent-${stop}`] = hex;
        }
        if (p.soft) out['--sp-accent-soft'] = p.soft;
        if (p.contrast) out['--sp-accent-contrast'] = p.contrast;
        (p.chart ?? []).forEach((hex, i) => {
            out[`--sp-chart-${i + 1}`] = hex;
        });
    }
    if (
        settings?.font &&
        settings.font !== 'sans' &&
        FONT_STACKS[settings.font]
    ) {
        out.fontFamily = FONT_STACKS[settings.font];
    }
    return out;
}
