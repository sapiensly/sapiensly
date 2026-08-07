/**
 * Reading a number off a machine plugged into the computer.
 *
 * A shop scale, a caliper, a bench meter: nearly all of them speak the same
 * ancient thing over a serial port — a line of text, a few times a second,
 * carrying the current reading and usually a word about whether it has settled.
 * Somebody reading that display and typing it into a form is the step that
 * produces the wrong weight on the invoice, so it is the step worth removing.
 *
 * Web Serial is Chromium-only and needs a user gesture and an explicit port
 * choice — the page cannot see the machine until somebody points at it, which
 * is the right shape for this and is why the button is never the only way in.
 */

/**
 * The number in one line of a scale's chatter, or null.
 *
 * Deliberately generous about the format and strict about one thing: a reading
 * the device says is UNSETTLED is refused. Every protocol in this family marks
 * that (`US`/`ST` in the Ohaus/AND family, a leading `?` elsewhere), and a
 * weight read while the pan is still moving is the wrong weight written down
 * with total confidence.
 */
export function parseWeight(line: string): number | null {
    const text = line.trim();
    if (text === '') return null;

    // Unstable, in the two spellings the common protocols use.
    if (/(^|[,\s])US([,\s]|$)/i.test(text) || text.startsWith('?')) {
        return null;
    }

    // The LAST number on the line: these frames lead with status words and
    // trail with the unit, and any leading digits belong to the status.
    //
    // The sign may be DETACHED — `ST,NT,-  0.250kg` is a real frame from a
    // tared scale, and a pattern that requires the sign to touch the digits
    // reads it as positive, storing the exact opposite of what the machine
    // said.
    const matches = text.match(/[+-]?\s*\d+(?:[.,]\d+)?/g);
    if (matches === null) return null;

    const value = Number(
        matches[matches.length - 1].replace(/\s+/g, '').replace(',', '.'),
    );

    return Number.isFinite(value) ? value : null;
}

/** Whether a serial device can be asked for anything here. */
export function canReadScale(): boolean {
    return (
        typeof navigator !== 'undefined' &&
        (navigator as unknown as { serial?: unknown }).serial !== undefined
    );
}

interface SerialPortLike {
    open: (options: { baudRate: number }) => Promise<void>;
    close: () => Promise<void>;
    readable: ReadableStream<Uint8Array> | null;
}

interface SerialLike {
    requestPort: () => Promise<SerialPortLike>;
}

/**
 * Ask for a reading, and give up rather than hang.
 *
 * Two agreeing readings in a row before it answers: a single line can be the
 * tail of the previous weight, and a scale settling shows its last wobble as a
 * perfectly well-formed number. Everything ends within `timeoutMs`, because a
 * button that never comes back is worse than one that says it could not read.
 */
export async function readWeight(
    options: { baudRate?: number; timeoutMs?: number } = {},
): Promise<number | null> {
    const serial = (navigator as unknown as { serial?: SerialLike }).serial;
    if (serial === undefined) return null;

    const baudRate = options.baudRate ?? 9600;
    const timeoutMs = options.timeoutMs ?? 12_000;

    let port: SerialPortLike | null = null;
    let reader: ReadableStreamDefaultReader<Uint8Array> | null = null;

    try {
        port = await serial.requestPort();
        await port.open({ baudRate });

        if (port.readable === null) return null;
        reader = port.readable.getReader();

        const deadline = Date.now() + timeoutMs;
        const decoder = new TextDecoder();
        let buffer = '';
        let previous: number | null = null;

        while (Date.now() < deadline) {
            const { value, done } = await Promise.race([
                reader.read(),
                new Promise<{ value: undefined; done: true }>((resolve) =>
                    setTimeout(
                        () => resolve({ value: undefined, done: true }),
                        Math.max(0, deadline - Date.now()),
                    ),
                ),
            ]);

            if (done) break;

            buffer += decoder.decode(value, { stream: true });

            // Both line endings, because the two families disagree and a device
            // that only sends \r would otherwise buffer for ever.
            const lines = buffer.split(/\r\n|\r|\n/);
            buffer = lines.pop() ?? '';

            for (const line of lines) {
                const reading = parseWeight(line);
                if (reading === null) continue;

                if (previous !== null && reading === previous) {
                    return reading;
                }

                previous = reading;
            }
        }

        return null;
    } catch {
        // No port chosen, a port already held by something else, a refusal.
        // The box beside the button still takes a typed number.
        return null;
    } finally {
        try {
            await reader?.cancel();
            reader?.releaseLock();
            await port?.close();
        } catch {
            // Closing a port that never opened. Nothing to undo.
        }
    }
}
