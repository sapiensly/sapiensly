/**
 * Whether a stored value is something a browser can actually load as an image.
 *
 * An image field is a plain string field — a manifest points at one by name and
 * whoever fills the record types into a text box. So it holds a URL when
 * somebody pasted a URL, and holds "foto del salón" when they described the
 * photo instead, which is a thing people do.
 *
 * Handing that second one to `<img src>` does not fail quietly: the browser
 * reserves the picture's whole height and paints a broken box, so a product
 * card came out with a hundred and twenty eight pixels of hole above its name.
 * A card with no picture is fine. A card with a hole is not.
 */
export function imageHref(value: unknown): string | null {
    if (typeof value !== 'string') return null;

    const src = value.trim();
    if (src === '') return null;

    // A data URI carries the image with it.
    if (/^data:image\//i.test(src)) return src;

    // An absolute URL, or one rooted on this host.
    if (/^https?:\/\//i.test(src) || src.startsWith('/')) {
        // Spaces are the tell of a sentence rather than an address. A real URL
        // that needs one has it encoded by whatever produced it.
        return /\s/.test(src) ? null : src;
    }

    return null;
}
