/**
 * Maps manifest `settings` (accent colour + font family) to an inline style
 * object applied to the runtime page surface, so the whole tree inherits the
 * brand accent (--sp-accent, used by buttons/links/highlights) and font.
 * Shared by the runtime page and the Builder preview.
 */
/**
 * The platform accent an app falls back to when its manifest sets none — the
 * client mirror of OrganizationBrand::DEFAULT_ACCENT, which the server already
 * derives every chart palette from. Anything accent-driven must default HERE,
 * so a brandless app still reads as one coherent colour.
 */
export const DEFAULT_ACCENT = '#0096ff';

const FONT_STACKS: Record<string, string> = {
    sans: 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif',
    serif: 'ui-serif, Georgia, Cambria, "Times New Roman", serif',
    rounded:
        '"SF Pro Rounded", ui-rounded, "Hiragino Maru Gothic ProN", "Quicksand", system-ui, sans-serif',
    mono: 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
};

/**
 * A #RRGGBB as the three HSL channels the runtime token layer derives from.
 *
 * The accent is published as channels rather than a colour so hover, press,
 * the tint behind a chip, the focus ring and the accent-as-text can all be
 * computed from it. Authoring those by hand is how a palette ends up tuned for
 * one blue and subtly wrong in every other colour a tenant picks.
 */
function toHsl(hex: string): { h: number; s: number; l: number } | null {
    const m = /^#([0-9a-fA-F]{6})$/.exec(hex);
    if (!m) return null;
    const n = parseInt(m[1], 16);
    const r = ((n >> 16) & 0xff) / 255;
    const g = ((n >> 8) & 0xff) / 255;
    const b = (n & 0xff) / 255;
    const max = Math.max(r, g, b);
    const min = Math.min(r, g, b);
    const l = (max + min) / 2;
    const d = max - min;
    if (d === 0) return { h: 0, s: 0, l: l * 100 };
    const s = d / (1 - Math.abs(2 * l - 1));
    const h =
        max === r
            ? ((g - b) / d + (g < b ? 6 : 0)) * 60
            : max === g
              ? ((b - r) / d + 2) * 60
              : ((r - g) / d + 4) * 60;

    return { h: Math.round(h), s: Math.round(s * 100), l: Math.round(l * 100) };
}

/**
 * Whether text on top of this colour should be dark. A tenant who picks lime or
 * amber gets black lettering on their buttons instead of the white that would
 * be unreadable there.
 */
function needsDarkInk(hex: string): boolean {
    const m = /^#([0-9a-fA-F]{6})$/.exec(hex);
    if (!m) return false;
    const n = parseInt(m[1], 16);
    const channel = (c: number): number => {
        const v = c / 255;
        return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    };
    const luminance =
        0.2126 * channel((n >> 16) & 0xff) +
        0.7152 * channel((n >> 8) & 0xff) +
        0.0722 * channel(n & 0xff);

    return luminance > 0.45;
}

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
    accent?: string;
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
    // The palette's accent is the EFFECTIVE one — `palette_mode: grays` neutralises
    // it server-side — so it wins over the raw brand accent. `settings.accent`
    // stays the user's authored choice (the builder's picker still shows it); only
    // what we PAINT with follows the mode.
    const accent = settings?.palette?.accent ?? settings?.accent;
    if (accent) {
        // Point both the generic accent AND the button/link accent
        // (--sp-accent-blue, which `bg-accent-blue` resolves) at the brand
        // colour, scoped to the app surface — so primary buttons, links and
        // highlights all adopt it.
        // The channels the token layer derives everything else from…
        const hsl = toHsl(accent);
        if (hsl !== null) {
            out['--sp-accent-h'] = String(hsl.h);
            out['--sp-accent-s'] = `${hsl.s}%`;
            out['--sp-accent-l'] = `${hsl.l}%`;
            out['--sp-on-accent'] = needsDarkInk(accent)
                ? '#101423'
                : '#ffffff';
        } else {
            // …and the flat value for anything that cannot be derived (a
            // palette hex the server sent in a form we do not parse).
            out['--sp-accent'] = accent;
        }
        out['--sp-accent-blue'] = accent;
        out['--sp-accent-blue-hover'] = darken(accent, 0.12);
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
