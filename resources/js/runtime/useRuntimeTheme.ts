import { inject, onUnmounted, ref, type InjectionKey, type Ref } from 'vue';
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
 * Reactive "is the current surface rendered dark?" — tracks the ambient `.dark`
 * class the platform toggles on <html> (via useAppearance), the SAME signal the
 * token palette flips on, so brand assets switch in lockstep with a live
 * light/dark toggle. SSR-safe: falls back to the declared runtime theme until the
 * client mounts and can read the DOM.
 */
export function useIsDarkSurface(): Ref<boolean> {
    const declared = useRuntimeTheme();
    const isDark = ref(declared === 'dark');

    if (typeof document !== 'undefined') {
        const sync = () =>
            (isDark.value =
                document.documentElement.classList.contains('dark'));
        sync();
        const observer = new MutationObserver(sync);
        observer.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class'],
        });
        onUnmounted(() => observer.disconnect());
    }

    return isDark;
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
