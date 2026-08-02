import { ref } from 'vue';

/**
 * Asking "are you sure" without handing the question to the browser.
 *
 * Both places that confirm a destructive action called `window.confirm`. That
 * dialog is the browser's, not the app's: it carries the origin ("sapiensly.test
 * says…"), ignores every token the runtime themes itself with, cannot show a
 * title separately from a message — `BlockButton` simply threw the title away —
 * and freezes the page thread while it is up.
 *
 * One dialog, mounted once by the renderer, asked for from anywhere:
 *
 *     if (!(await confirmAction({ title, message, locale }))) return;
 *
 * A drop-in shape for what it replaces, so a call site reads the same.
 */
export interface ConfirmRequest {
    title?: string;
    message: string;
    /** The APP's locale — the buttons are the runtime speaking, not the app. */
    locale?: string;
    /** Style the accept button as destructive. */
    danger?: boolean;
}

interface PendingConfirm extends ConfirmRequest {
    resolve: (answer: boolean) => void;
}

/** Null when nothing is being asked. The dialog renders off this. */
export const pendingConfirm = ref<PendingConfirm | null>(null);

export function confirmAction(request: ConfirmRequest): Promise<boolean> {
    // A second question while one is open would strand the first promise
    // unresolved and leave whatever was awaiting it hanging for ever.
    if (pendingConfirm.value !== null) {
        return Promise.resolve(false);
    }

    return new Promise<boolean>((resolve) => {
        pendingConfirm.value = { ...request, resolve };
    });
}

export function answerConfirm(answer: boolean): void {
    const pending = pendingConfirm.value;
    pendingConfirm.value = null;
    pending?.resolve(answer);
}
