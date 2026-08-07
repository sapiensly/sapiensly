/**
 * What gets burned into the corner of an evidence photo.
 *
 * A plain photo proves what was in front of a lens. It does not prove WHEN, and
 * it does not prove WHERE — which are the two things anybody disputing a meter
 * reading, a delivery or damage on arrival actually disputes. Writing them into
 * the pixels is not tamper-proof and is not pretending to be: it is the same
 * thing a dashcam does, so that the picture travels with its own claim instead
 * of relying on a filename nobody kept.
 *
 * Split from the component because the words are the part worth testing and a
 * canvas in jsdom is not.
 */
export interface StampPoint {
    lat: number;
    lng: number;
    accuracy?: number;
}

/**
 * Six decimals is about ten centimetres — far finer than any phone's fix, and
 * chosen to match what the `geo` field already stores so the number written on
 * the photo and the number in the record are the same number.
 */
function coordinate(value: number): string {
    return value.toFixed(6);
}

/**
 * The lines, top to bottom.
 *
 * The time is always there; the place only when the device gave one. A refused
 * location writes the time alone rather than failing the photo — somebody with
 * location off still needs to record the meter, and half a claim is worth more
 * than none.
 */
export function stampLines(
    at: Date,
    point: StampPoint | null,
    locale: string,
): string[] {
    const lines: string[] = [];

    try {
        lines.push(
            new Intl.DateTimeFormat(locale, {
                dateStyle: 'short',
                timeStyle: 'short',
            }).format(at),
        );
    } catch {
        // An unknown locale tag. The moment matters more than its formatting.
        lines.push(at.toISOString());
    }

    if (point !== null) {
        const accuracy =
            typeof point.accuracy === 'number' &&
            Number.isFinite(point.accuracy)
                ? `  ±${Math.round(point.accuracy)} m`
                : '';

        lines.push(
            `${coordinate(point.lat)}, ${coordinate(point.lng)}${accuracy}`,
        );
    }

    return lines;
}

/**
 * Draw the lines over the bottom-left of an image.
 *
 * Scaled off the image's own height rather than fixed: the same code writes a
 * legible stamp on a 480p webcam frame and on a 12-megapixel phone photo, and a
 * 14px caption on the second one is a smudge nobody can read.
 */
export function drawStamp(
    ctx: CanvasRenderingContext2D,
    width: number,
    height: number,
    lines: string[],
): void {
    if (lines.length === 0) return;

    const size = Math.max(12, Math.round(height * 0.028));
    const pad = Math.round(size * 0.6);
    const lineHeight = Math.round(size * 1.35);
    const boxHeight = lines.length * lineHeight + pad * 2;

    ctx.save();
    // A band rather than plain text: white letters vanish on a white wall and
    // black ones vanish on asphalt, and an evidence photo that cannot be read
    // is not evidence.
    ctx.fillStyle = 'rgba(0, 0, 0, 0.55)';
    ctx.fillRect(0, height - boxHeight, width, boxHeight);

    ctx.fillStyle = '#ffffff';
    ctx.font = `${size}px ui-monospace, SFMono-Regular, Menlo, monospace`;
    ctx.textBaseline = 'top';

    lines.forEach((line, index) => {
        ctx.fillText(line, pad, height - boxHeight + pad + index * lineHeight);
    });

    ctx.restore();
}
