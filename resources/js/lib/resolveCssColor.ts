/**
 * Resolve a `var(--name, fallback)` color string to its concrete value by
 * reading the CSS variable off an element (the app surface sets the palette
 * vars). Chart components need REAL hex values — they derive hues and build
 * color-mix()/conic-gradient() strings — so a raw var() reference silently
 * falls back to their default palette.
 */
export function resolveCssColor(color: string, el?: Element | null): string {
    const m = /^var\((--[\w-]+)\s*(?:,\s*(.*))?\)$/.exec(color.trim());
    if (!m) return color;
    const fallback = (m[2] ?? '').trim();
    if (typeof window === 'undefined') return fallback || color;
    const scope = el ?? document.documentElement;
    const value = getComputedStyle(scope).getPropertyValue(m[1]).trim();
    return value || fallback || color;
}
