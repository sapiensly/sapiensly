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
 * Token bundles per theme. Centralised so every block component can pick the
 * right classes without duplicating ternaries.
 */
export function themeTokens(theme: RuntimeTheme) {
    if (theme === 'light') {
        return {
            surface: 'bg-white border-zinc-200',
            surfaceMuted: 'bg-zinc-50 border-zinc-200',
            text: 'text-zinc-900',
            textMuted: 'text-zinc-500',
            textSubtle: 'text-zinc-400',
            headerRow: 'bg-zinc-50 text-zinc-500',
            rowBorder: 'divide-zinc-200',
            statTint: 'text-zinc-900',
            divider: 'border-zinc-200',
        };
    }
    return {
        surface: 'bg-navy border-soft',
        surfaceMuted: 'bg-white/5 border-soft',
        text: 'text-ink',
        textMuted: 'text-ink-muted',
        textSubtle: 'text-ink-subtle',
        headerRow: 'bg-navy text-ink-muted',
        rowBorder: 'divide-soft',
        statTint: 'text-ink',
        divider: 'border-soft',
    };
}
