/**
 * The device, asked for the things a web page is allowed to ask it for.
 *
 * Every function here follows the rail the captures already follow: the browser
 * that cannot do it is the normal case, not the error case, so each one answers
 * with what actually happened instead of throwing, and the caller always has
 * somewhere to go next. A phone is not a required accessory for using an app —
 * it is the best case, and the desktop is the one that must keep working.
 *
 * Kept out of the components that use them because two of the three already
 * have two callers each, and because a jsdom test can reach a plain function.
 */

/**
 * A short buzz.
 *
 * Silent everywhere it is unsupported — iOS Safari has no Vibration API at all,
 * so a guard is not a nicety. Worth having anyway: the one thing an operator
 * holding a phone at arm's length over a pallet cannot do is watch the screen
 * for a green flash, and a scan that confirms itself in the hand is the
 * difference between scanning twice and scanning once.
 */
export function haptic(pattern: number | number[] = 20): void {
    if (typeof navigator === 'undefined') return;

    try {
        navigator.vibrate?.(pattern);
    } catch {
        // A page that has never been interacted with is refused by some
        // browsers. Nothing to tell anyone about.
    }
}

/**
 * Text on the clipboard.
 *
 * `navigator.clipboard` needs a secure context and a recent gesture, and in
 * enough real cases (an http:// LAN address, a webview, a permission policy) it
 * is simply absent — hence the execCommand fallback, which is deprecated and
 * still the only thing that works there. Returns whether it landed, because
 * "Copied" toasted over a clipboard that never changed is worse than saying
 * nothing.
 */
export async function copyText(text: string): Promise<boolean> {
    if (text === '') return false;

    try {
        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(text);

            return true;
        }
    } catch {
        // Fall through — the old way still works in the places this fails.
    }

    if (typeof document === 'undefined') return false;

    try {
        const area = document.createElement('textarea');
        area.value = text;
        // Off-screen rather than hidden: a display:none element cannot be
        // selected, which is the whole mechanism.
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.left = '-9999px';
        document.body.appendChild(area);
        area.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(area);

        return ok;
    } catch {
        return false;
    }
}

/**
 * What became of a share.
 *
 * `dismissed` is its own outcome and not a failure: somebody who opened the
 * share sheet and changed their mind has not had a problem, and falling back to
 * the clipboard for them would put a link they decided against onto it.
 */
export type ShareOutcome = 'shared' | 'copied' | 'dismissed' | 'failed';

export interface SharePayload {
    title?: string;
    text?: string;
    url?: string;
    files?: File[];
}

/**
 * Hand something to whatever the person shares things with.
 *
 * The Web Share API is the only route from an app to WhatsApp, and WhatsApp is
 * how the signed work order actually reaches the customer — who does not have
 * the app and is not going to be given a login. Where the API is missing
 * (desktop Firefox, most webviews) the link goes to the clipboard instead,
 * which is the same job done by hand.
 *
 * Files are checked with `canShare` first: passing a PDF to a browser that
 * shares text only throws, and the throw is indistinguishable from the person
 * cancelling.
 */
export async function shareContent(
    payload: SharePayload,
): Promise<ShareOutcome> {
    const hasFiles = (payload.files?.length ?? 0) > 0;
    const nav = typeof navigator === 'undefined' ? undefined : navigator;

    if (nav?.share) {
        const shareable =
            hasFiles && nav.canShare !== undefined
                ? nav.canShare({ files: payload.files })
                : !hasFiles;

        if (shareable) {
            try {
                await nav.share(payload);

                return 'shared';
            } catch (error) {
                // The sheet opened and they closed it. Not a failure, and
                // deliberately not a reason to copy anything.
                if ((error as DOMException)?.name === 'AbortError') {
                    return 'dismissed';
                }
            }
        }
    }

    // No sheet, or it refused what we offered. A file cannot go on a
    // clipboard, so only a link or a message falls back this way.
    const fallback = payload.url ?? payload.text ?? '';
    if (fallback !== '' && (await copyText(fallback))) {
        return 'copied';
    }

    return 'failed';
}

/**
 * Say it out loud.
 *
 * For the half of a job done with both hands full: the next address on the
 * route, the quantity to pick, the name of whoever is at the door. Cancels
 * whatever is still being said first — two utterances queue by default, so a
 * button pressed twice reads the first message to the end before starting the
 * second, which sounds exactly like the app being stuck.
 *
 * Returns whether it started, so a caller can say the words on screen instead.
 */
export function speak(text: string, lang?: string): boolean {
    if (typeof window === 'undefined' || text === '') return false;

    const synth = window.speechSynthesis;
    if (synth === undefined) return false;

    try {
        synth.cancel();
        const utterance = new SpeechSynthesisUtterance(text);
        if (lang !== undefined && lang !== '') {
            utterance.lang = lang;
        }
        synth.speak(utterance);

        return true;
    } catch {
        return false;
    }
}

/** Whether this browser can be asked to keep the screen on. */
export function canKeepAwake(): boolean {
    return typeof navigator !== 'undefined' && 'wakeLock' in navigator;
}

/** What the phone's contact picker gave back. Every part is optional. */
export interface PickedContact {
    name?: string;
    email?: string;
    tel?: string;
}

/** Whether this browser has a contact picker at all (Chrome on Android). */
export function canPickContact(): boolean {
    return (
        typeof navigator !== 'undefined' &&
        (navigator as unknown as { contacts?: unknown }).contacts !== undefined
    );
}

/**
 * A name, a number and an address, out of the phone's own address book.
 *
 * The picker is the OS's — this page never sees the contact list, only the one
 * entry somebody chose, which is the whole reason the API is shaped this way
 * and worth saying out loud in a product that also stores customer data.
 *
 * Properties are asked for by NAME after checking what this device supports:
 * requesting one it does not have throws, and the throw would be
 * indistinguishable from the person closing the picker.
 */
export async function pickContact(): Promise<PickedContact | null> {
    const contacts = (
        navigator as unknown as {
            contacts?: {
                getProperties: () => Promise<string[]>;
                select: (
                    props: string[],
                    options?: { multiple?: boolean },
                ) => Promise<
                    Array<{ name?: string[]; email?: string[]; tel?: string[] }>
                >;
            };
        }
    ).contacts;

    if (contacts === undefined) return null;

    try {
        const supported = await contacts.getProperties();
        const wanted = ['name', 'email', 'tel'].filter((p) =>
            supported.includes(p),
        );
        if (wanted.length === 0) return null;

        const [picked] = await contacts.select(wanted, { multiple: false });
        if (picked === undefined) return null;

        return {
            ...(picked.name?.[0] !== undefined ? { name: picked.name[0] } : {}),
            ...(picked.email?.[0] !== undefined
                ? { email: picked.email[0] }
                : {}),
            ...(picked.tel?.[0] !== undefined ? { tel: picked.tel[0] } : {}),
        };
    } catch {
        // Closed the picker, or refused. Either way the box beside the button
        // is still a box.
        return null;
    }
}

/** Whether a screen or a window can be captured here (desktop browsers). */
export function canCaptureScreen(): boolean {
    return (
        typeof navigator !== 'undefined' &&
        navigator.mediaDevices?.getDisplayMedia !== undefined
    );
}

/**
 * A picture of what somebody is looking at.
 *
 * For the support ticket where the screen IS the report: describing a dialog in
 * words is the step that loses the detail somebody needed. The browser asks
 * which window to share and shows it doing so — this cannot take a picture of
 * anything unattended.
 *
 * One frame, then the track is stopped immediately. A capture stream left open
 * is a screen still being read, and the browser's own indicator would keep
 * saying so long after the photo was taken.
 */
export async function captureScreen(): Promise<Blob | null> {
    if (!canCaptureScreen()) return null;

    let stream: MediaStream | null = null;

    try {
        stream = await navigator.mediaDevices.getDisplayMedia({ video: true });

        const video = document.createElement('video');
        video.srcObject = stream;
        video.muted = true;
        await video.play();

        // One rendered frame: `play()` resolves before the first one is
        // painted, and a canvas drawn a moment too early is a black rectangle.
        await new Promise((resolve) => requestAnimationFrame(resolve));

        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        if (canvas.width === 0 || canvas.height === 0) return null;
        canvas.getContext('2d')?.drawImage(video, 0, 0);

        return await new Promise<Blob | null>((resolve) => {
            canvas.toBlob((blob) => resolve(blob), 'image/png');
        });
    } catch {
        return null;
    } finally {
        stream?.getTracks().forEach((track) => track.stop());
    }
}

/**
 * Full screen, or back out of it.
 *
 * For the app running on the tablet bolted to a counter: the browser chrome
 * above it is somebody's escape hatch into the rest of the internet, and a till
 * that can be navigated away from is a till that will be. Requires a gesture,
 * which is why it is an action on a button and not a page setting.
 */
export async function toggleFullscreen(): Promise<boolean> {
    if (typeof document === 'undefined') return false;

    try {
        if (document.fullscreenElement) {
            await document.exitFullscreen();

            return false;
        }

        await document.documentElement.requestFullscreen();

        return true;
    } catch {
        // iOS Safari on iPhone has no Fullscreen API. The app is simply not
        // full screen, which is what it was a moment ago.
        return false;
    }
}
