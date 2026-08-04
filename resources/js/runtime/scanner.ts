import { ref } from 'vue';

/**
 * Asking for a barcode from anywhere.
 *
 * The field had its own scanner instance; then an ACTION needed one too — a
 * button that reads a code and opens the record it names. Two consumers is
 * where the shape is known rather than guessed, so the sheet moved out here and
 * is mounted once by the renderer, exactly like {@see ./confirm}:
 *
 *     const code = await requestScan(locale);
 *
 * One instance matters more than tidiness: two live sheets would mean two
 * `getUserMedia` grabs on the same camera, and the second one wins while the
 * first keeps its track open. On a phone that shows up as a dead viewfinder
 * nobody can explain.
 */
interface PendingScan {
    locale: string;
    /** null when the person closed the sheet without reading anything. */
    resolve: (value: string | null) => void;
}

/** Null when nothing is being scanned. The sheet renders off this. */
export const pendingScan = ref<PendingScan | null>(null);

export function requestScan(locale: string): Promise<string | null> {
    // A second request while one is open would strand the first promise
    // unresolved and leave whatever was awaiting it hanging for ever.
    if (pendingScan.value !== null) {
        return Promise.resolve(null);
    }

    return new Promise<string | null>((resolve) => {
        pendingScan.value = { locale, resolve };
    });
}

export function answerScan(value: string | null): void {
    const pending = pendingScan.value;
    pendingScan.value = null;
    pending?.resolve(value);
}
