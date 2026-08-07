import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { useDictation } from './useDictation';

/**
 * The microphone that types.
 *
 * What matters is which words reach the box: only the phrases the recogniser
 * SETTLED on. Interim results are shown so somebody can see it is working and
 * are never handed over — a box filled from them ends up with each phrase
 * written three times as the guess is refined.
 */
interface FakeRecognition {
    lang: string;
    continuous: boolean;
    interimResults: boolean;
    start: () => void;
    stop: () => void;
    onresult: ((event: unknown) => void) | null;
    onerror: (() => void) | null;
    onend: (() => void) | null;
}

let live: FakeRecognition | null = null;

function installRecogniser(): void {
    // A constructor that RETURNS an object hands that object back from `new`,
    // which is how the test keeps a handle on the session the composable built
    // for itself: whatever was constructed last is live.
    const make = (): FakeRecognition => {
        const session: FakeRecognition = {
            lang: '',
            continuous: false,
            interimResults: false,
            start: vi.fn(),
            stop: vi.fn(),
            onresult: null,
            onerror: null,
            onend: null,
        };
        live = session;

        return session;
    };

    // Not an arrow: `new` refuses one, and the composable uses `new`.
    Object.defineProperty(window, 'SpeechRecognition', {
        configurable: true,
        value: function FakeRecogniser() {
            return make();
        },
    });
}

function removeRecogniser(): void {
    Object.defineProperty(window, 'SpeechRecognition', {
        configurable: true,
        value: undefined,
    });
    Object.defineProperty(window, 'webkitSpeechRecognition', {
        configurable: true,
        value: undefined,
    });
}

/** A results payload shaped like the browser's, which is an ArrayLike of ArrayLike. */
function results(entries: Array<{ text: string; final: boolean }>): {
    resultIndex: number;
    results: unknown;
} {
    return {
        resultIndex: 0,
        results: entries.map((entry) =>
            Object.assign([{ transcript: entry.text }], {
                isFinal: entry.final,
            }),
        ),
    };
}

/** The composable needs an owner for its unmount hook. */
function harness() {
    const spoken: string[] = [];
    let api: ReturnType<typeof useDictation>;

    const wrapper = mount(
        defineComponent({
            setup() {
                api = useDictation(() => 'es-MX');

                return () => h('div');
            },
        }),
    );

    return { wrapper, spoken, api: api! };
}

afterEach(() => {
    live = null;
});

describe('dictating into one box', () => {
    it('says so when the browser has no recogniser', () => {
        // Firefox, most of Safari. The caller reads this to decide whether to
        // offer a microphone at all — a button that cannot work is worse than
        // no button.
        removeRecogniser();

        const { api, wrapper } = harness();

        expect(api.supported).toBe(false);
        wrapper.unmount();
    });

    it('hands over settled phrases and never the guesses', () => {
        installRecogniser();

        const { api, spoken, wrapper } = harness();
        api.start((text) => spoken.push(text));

        live?.onresult?.(results([{ text: 'la bomba está', final: false }]));
        expect(spoken).toEqual([]);
        expect(api.interim.value).toBe('la bomba está');

        live?.onresult?.(
            results([{ text: 'la bomba está fugando', final: true }]),
        );
        expect(spoken).toEqual(['la bomba está fugando']);

        wrapper.unmount();
    });

    it('speaks the app’s language, not the browser’s', () => {
        installRecogniser();

        const { api, wrapper } = harness();
        api.start(() => undefined);

        expect(live?.lang).toBe('es-MX');
        // A session that never ends is a microphone left open on a form
        // somebody walked away from.
        expect(live?.continuous).toBe(false);

        wrapper.unmount();
    });

    it('goes back to idle when the recogniser stops on its own', () => {
        installRecogniser();

        const { api, wrapper } = harness();
        api.start(() => undefined);
        expect(api.listening.value).toBe(true);

        live?.onend?.();

        expect(api.listening.value).toBe(false);
        expect(api.interim.value).toBe('');

        wrapper.unmount();
    });

    it('stops listening when the field goes away', () => {
        installRecogniser();

        const { api, wrapper } = harness();
        api.start(() => undefined);
        const session = live;

        wrapper.unmount();

        expect(session?.stop).toHaveBeenCalled();
    });
});
