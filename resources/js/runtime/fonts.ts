/**
 * settings.fonts — extra web-font families beyond the self-hosted catalog.
 * Any Google Fonts family, served from the privacy-friendly mirror
 * (fonts.bunny.net — same families and CSS API, no Google tracking, and a
 * host the platform already uses). Specs look like "Space Grotesk" or
 * "Space Grotesk:400,700,400i". The manifest schema validates each spec;
 * this mirrors that validation so an unexpected value never becomes a URL.
 */
const FONT_SPEC = /^[A-Za-z0-9][A-Za-z0-9 ]{0,48}(:[0-9]{3}i?(,[0-9]{3}i?)*)?$/;

const MAX_FAMILIES = 4;

export function manifestFontHrefs(fonts: unknown): string[] {
    if (!Array.isArray(fonts)) return [];
    return fonts
        .filter(
            (f): f is string => typeof f === 'string' && FONT_SPEC.test(f.trim()),
        )
        .slice(0, MAX_FAMILIES)
        .map((spec) => {
            const [family, weights] = spec.trim().split(':');
            const slug = family.trim().toLowerCase().replace(/ +/g, '-');
            return `https://fonts.bunny.net/css?family=${slug}${weights ? ':' + weights : ''}&display=swap`;
        });
}

/**
 * Idempotently ensure <link> tags exist for the given manifest fonts. For
 * client-only contexts (the builder's live preview and the off-screen
 * draft-shot pane); the runtime page renders its links in <Head> instead so
 * SSR ships them.
 */
export function ensureManifestFontLinks(fonts: unknown): void {
    if (typeof document === 'undefined') return;
    for (const href of manifestFontHrefs(fonts)) {
        const escaped = href.replace(/"/g, '\\"');
        if (
            !document.querySelector(
                `link[data-sp-manifest-font][href="${escaped}"]`,
            )
        ) {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = href;
            link.setAttribute('data-sp-manifest-font', '');
            document.head.appendChild(link);
        }
    }
}
