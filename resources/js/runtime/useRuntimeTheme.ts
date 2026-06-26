import { inject, type InjectionKey } from 'vue';
import type { RuntimeTheme } from './types/manifest';

export const ThemeKey: InjectionKey<RuntimeTheme> = Symbol('runtime-theme');

/**
 * Read the theme provided by the nearest AppRenderer ancestor. Defaults to
 * 'dark' so blocks rendered outside the runtime (or before provide() runs)
 * still pick the platform-matching palette.
 */
export function useRuntimeTheme(): RuntimeTheme {
    return inject(ThemeKey, 'dark');
}

/**
 * Token classes for block components. Every class is backed by a `--sp-*`
 * custom property (through the `--color-*` theme map), so the whole bundle
 * flips automatically with the `.dark` class the platform sets on <html> — the
 * app runtime follows the user's light/dark preference, no per-theme branching.
 *
 * The `theme` arg is accepted for backwards compatibility but is no longer used:
 * the palette is driven entirely by the ambient `.dark` class.
 */
// eslint-disable-next-line @typescript-eslint/no-unused-vars
export function themeTokens(theme?: RuntimeTheme) {
    return {
        surface: 'bg-navy border-soft',
        surfaceMuted: 'bg-surface border-soft',
        text: 'text-ink',
        textMuted: 'text-ink-muted',
        textSubtle: 'text-ink-subtle',
        headerRow: 'bg-navy text-ink-muted',
        rowBorder: 'divide-soft',
        statTint: 'text-ink',
        divider: 'border-soft',
    };
}
