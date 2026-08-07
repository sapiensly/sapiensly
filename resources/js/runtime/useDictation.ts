import { onBeforeUnmount, ref } from 'vue';

/**
 * Typing with your voice, using the browser's own recogniser.
 *
 * Deliberately NOT the same thing as the form's "dictate it" button, which
 * records audio and sends it to a model to fill in a whole form. This one fills
 * in ONE box, costs nothing per use, uploads nothing and answers immediately —
 * which is what somebody standing next to the machine they are describing
 * actually wants for the findings field.
 *
 * Chrome and Edge have it; Firefox and much of Safari do not, and there is no
 * ponyfill worth shipping (the alternative is a model, which is the other
 * button). So `supported` is read by the caller to decide whether to offer the
 * microphone at all — a button that cannot work is worse than no button.
 */
interface RecognitionLike {
    lang: string;
    continuous: boolean;
    interimResults: boolean;
    start: () => void;
    stop: () => void;
    onresult: ((event: SpeechRecognitionEventLike) => void) | null;
    onerror: (() => void) | null;
    onend: (() => void) | null;
}

interface SpeechRecognitionEventLike {
    resultIndex: number;
    results: ArrayLike<
        ArrayLike<{ transcript: string }> & { isFinal: boolean }
    >;
}

function recogniser(): (new () => RecognitionLike) | undefined {
    if (typeof window === 'undefined') return undefined;

    const w = window as unknown as {
        SpeechRecognition?: new () => RecognitionLike;
        webkitSpeechRecognition?: new () => RecognitionLike;
    };

    return w.SpeechRecognition ?? w.webkitSpeechRecognition;
}

export function useDictation(locale: () => string) {
    const supported = recogniser() !== undefined;
    const listening = ref(false);
    /** What is being heard right now, before it settles. Shown, never stored. */
    const interim = ref('');

    let session: RecognitionLike | null = null;

    function stop(): void {
        listening.value = false;
        interim.value = '';
        session?.stop();
        session = null;
    }

    /**
     * Listen, and hand back each settled phrase.
     *
     * Phrase by phrase rather than once at the end, so a long note appears as
     * it is spoken — and so a recogniser that gives up halfway (they all do,
     * on a pause) leaves behind everything it had already understood instead of
     * nothing.
     */
    function start(onPhrase: (text: string) => void): void {
        const Recognition = recogniser();
        if (Recognition === undefined || listening.value) return;

        const active = new Recognition();
        session = active;
        active.lang = locale();
        // Not `continuous`: the browser's own end-of-speech detection is what
        // stops the microphone, and a session that never ends is a microphone
        // left open on a form somebody walked away from.
        active.continuous = false;
        active.interimResults = true;

        active.onresult = (event) => {
            let pending = '';

            for (let i = event.resultIndex; i < event.results.length; i += 1) {
                const result = event.results[i];
                const text = result[0]?.transcript ?? '';

                if (result.isFinal) {
                    const settled = text.trim();
                    if (settled !== '') onPhrase(settled);
                } else {
                    pending += text;
                }
            }

            interim.value = pending;
        };

        active.onerror = () => stop();
        active.onend = () => {
            // Ended on its own — a pause, a timeout, silence. The box keeps
            // whatever was understood and the button goes back to idle.
            listening.value = false;
            interim.value = '';
            session = null;
        };

        try {
            active.start();
            listening.value = true;
        } catch {
            // Already running, or refused. Nothing to say: the box is still a
            // box and the keyboard still works.
            session = null;
        }
    }

    onBeforeUnmount(stop);

    return { supported, listening, interim, start, stop };
}
