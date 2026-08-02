/**
 * How many of a row's items fit, given their widths and the room available.
 *
 * Pure on purpose. Reading widths out of the DOM needs a browser, but deciding
 * what to do with them does not — and the deciding is where the bugs are. Three
 * attempts at this lived inside a component, where the only way to see the
 * answer was a screenshot of the whole app; here the answer is a number.
 */
export interface FitOptions {
    /** What the "more" control costs once it has to exist. */
    overflowWidth: number;
    /** The gap between two items. */
    gap: number;
}

/**
 * Returns how many leading items to show. The rest belong behind the overflow
 * control — which itself needs room, so making space for it can push out one
 * more item than the raw arithmetic suggests.
 *
 * An unmeasured row (any width still zero, or a count that does not match)
 * shows everything: that is the state the measurement itself depends on, and
 * folding on a guess hides links nothing will bring back.
 */
export function fitCount(
    widths: number[],
    available: number,
    { overflowWidth, gap }: FitOptions,
): number {
    if (widths.length === 0) return 0;
    if (widths.some((w) => w <= 0)) return widths.length;
    if (!Number.isFinite(available) || available <= 0) return widths.length;

    const total = widths.reduce((sum, w) => sum + w + gap, 0) - gap;
    if (total <= available) return widths.length;

    // Everything from here on shares the row with the overflow control.
    const room = available - overflowWidth - gap;
    let used = 0;
    let fits = 0;
    for (const w of widths) {
        const next = used === 0 ? w : used + gap + w;
        if (next > room) break;
        used = next;
        fits++;
    }

    return fits;
}
