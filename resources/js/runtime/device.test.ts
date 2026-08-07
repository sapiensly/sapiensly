import { afterEach, describe, expect, it, vi } from 'vitest';
import { copyText, haptic, shareContent, speak } from './device';

/**
 * The device seam.
 *
 * Every one of these runs on a browser that may not have the API at all, so
 * what is asserted is mostly the SHAPE of the giving up: a share the person
 * cancelled must not be treated as a failure (and must not quietly copy a link
 * they decided against), a copy that did not land must not report success, and
 * nothing here may throw into a click handler.
 */
const original = {
    clipboard: navigator.clipboard,
    share: (navigator as { share?: unknown }).share,
    canShare: (navigator as { canShare?: unknown }).canShare,
    vibrate: navigator.vibrate,
};

function stub(name: string, value: unknown): void {
    Object.defineProperty(navigator, name, {
        value,
        configurable: true,
        writable: true,
    });
}

afterEach(() => {
    stub('clipboard', original.clipboard);
    stub('share', original.share);
    stub('canShare', original.canShare);
    stub('vibrate', original.vibrate);
});

describe('copying text', () => {
    it('uses the clipboard when there is one', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        stub('clipboard', { writeText });

        expect(await copyText('ABC-123')).toBe(true);
        expect(writeText).toHaveBeenCalledWith('ABC-123');
    });

    it('falls back to a selection when the clipboard is refused', async () => {
        // An http:// LAN address, a webview, a permission policy: the API is
        // there and throws, or is not there at all. The old way still works.
        stub('clipboard', {
            writeText: vi.fn().mockRejectedValue(new Error('denied')),
        });
        const exec = vi.fn().mockReturnValue(true);
        Object.defineProperty(document, 'execCommand', {
            value: exec,
            configurable: true,
        });

        expect(await copyText('ABC-123')).toBe(true);
        expect(exec).toHaveBeenCalledWith('copy');
    });

    it('reports failure rather than claiming a copy that never happened', async () => {
        stub('clipboard', undefined);
        Object.defineProperty(document, 'execCommand', {
            value: vi.fn().mockReturnValue(false),
            configurable: true,
        });

        expect(await copyText('ABC-123')).toBe(false);
    });

    it('does nothing with nothing', async () => {
        expect(await copyText('')).toBe(false);
    });
});

describe('sharing', () => {
    it('hands the payload to the share sheet', async () => {
        const share = vi.fn().mockResolvedValue(undefined);
        stub('share', share);

        expect(await shareContent({ url: 'https://x.test/o/1' })).toBe(
            'shared',
        );
        expect(share).toHaveBeenCalledWith({ url: 'https://x.test/o/1' });
    });

    it('treats a cancelled sheet as its own outcome, and copies nothing', async () => {
        // Somebody who opened the sheet and changed their mind has not had a
        // problem. Putting the link on their clipboard anyway would be acting
        // on the decision they just reversed.
        const abort = Object.assign(new Error('cancelled'), {
            name: 'AbortError',
        });
        stub('share', vi.fn().mockRejectedValue(abort));
        const writeText = vi.fn().mockResolvedValue(undefined);
        stub('clipboard', { writeText });

        expect(await shareContent({ url: 'https://x.test/o/1' })).toBe(
            'dismissed',
        );
        expect(writeText).not.toHaveBeenCalled();
    });

    it('copies the link where there is no share sheet', async () => {
        stub('share', undefined);
        const writeText = vi.fn().mockResolvedValue(undefined);
        stub('clipboard', { writeText });

        expect(await shareContent({ url: 'https://x.test/o/1' })).toBe(
            'copied',
        );
        expect(writeText).toHaveBeenCalledWith('https://x.test/o/1');
    });

    it('asks whether a file can be shared before offering one', async () => {
        // Passing a PDF to a text-only sheet throws, and the throw is
        // indistinguishable from the person cancelling.
        const share = vi.fn().mockResolvedValue(undefined);
        stub('share', share);
        stub('canShare', vi.fn().mockReturnValue(false));
        stub('clipboard', undefined);
        Object.defineProperty(document, 'execCommand', {
            value: vi.fn().mockReturnValue(false),
            configurable: true,
        });

        const file = new File(['%PDF'], 'orden.pdf', {
            type: 'application/pdf',
        });

        expect(await shareContent({ files: [file] })).toBe('failed');
        expect(share).not.toHaveBeenCalled();
    });

    it('shares a file when the sheet accepts one', async () => {
        const share = vi.fn().mockResolvedValue(undefined);
        stub('share', share);
        stub('canShare', vi.fn().mockReturnValue(true));

        const file = new File(['%PDF'], 'orden.pdf', {
            type: 'application/pdf',
        });

        expect(await shareContent({ files: [file], title: 'Orden' })).toBe(
            'shared',
        );
    });
});

describe('speaking', () => {
    it('cancels whatever is still being said', () => {
        // Two utterances queue by default, so a button pressed twice reads the
        // first message to the end before starting the second — which sounds
        // exactly like the app being stuck.
        const cancel = vi.fn();
        const speakFn = vi.fn();
        Object.defineProperty(window, 'speechSynthesis', {
            value: { cancel, speak: speakFn },
            configurable: true,
        });
        Object.defineProperty(window, 'SpeechSynthesisUtterance', {
            value: class {
                lang = '';

                constructor(public text: string) {}
            },
            configurable: true,
        });

        expect(speak('Siguiente parada: Reforma 222', 'es-MX')).toBe(true);
        expect(cancel).toHaveBeenCalled();
        expect(speakFn.mock.calls[0][0]).toMatchObject({
            text: 'Siguiente parada: Reforma 222',
            lang: 'es-MX',
        });
    });

    it('says it could not speak instead of pretending it did', () => {
        Object.defineProperty(window, 'speechSynthesis', {
            value: undefined,
            configurable: true,
        });

        expect(speak('hola')).toBe(false);
    });
});

describe('haptics', () => {
    it('stays silent where there is no vibration motor', () => {
        stub('vibrate', undefined);

        expect(() => haptic()).not.toThrow();
    });

    it('never throws out of a click handler', () => {
        stub(
            'vibrate',
            vi.fn(() => {
                throw new Error('not allowed without a gesture');
            }),
        );

        expect(() => haptic([10, 30, 10])).not.toThrow();
    });
});
