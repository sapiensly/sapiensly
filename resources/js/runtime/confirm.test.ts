import { beforeEach, describe, expect, it } from 'vitest';
import { answerConfirm, confirmAction, pendingConfirm } from './confirm';

/**
 * The runtime's own "are you sure".
 *
 * Both places that confirmed a destructive action handed the question to
 * `window.confirm` — the browser's dialog, carrying the origin, ignoring every
 * theme token, unable to show a title beside a message (BlockButton simply threw
 * the authored title away) and freezing the page thread while it was up.
 */
describe('asking before something irreversible', () => {
    beforeEach(() => {
        pendingConfirm.value = null;
    });

    it('resolves to what was answered', async () => {
        const yes = confirmAction({ message: '¿Seguro?' });
        answerConfirm(true);
        await expect(yes).resolves.toBe(true);

        const no = confirmAction({ message: '¿Seguro?' });
        answerConfirm(false);
        await expect(no).resolves.toBe(false);
    });

    it('carries the title, which the browser dialog could not', () => {
        void confirmAction({
            message: 'Se quita para todos.',
            title: '¿Eliminar Cliente?',
        });

        expect(pendingConfirm.value?.title).toBe('¿Eliminar Cliente?');
        expect(pendingConfirm.value?.message).toBe('Se quita para todos.');
    });

    it('refuses a second question rather than stranding the first', async () => {
        // Whatever awaited the first promise would hang for ever, and the
        // dialog can only show one thing at a time anyway.
        const first = confirmAction({ message: 'una' });
        const second = confirmAction({ message: 'otra' });

        await expect(second).resolves.toBe(false);
        expect(pendingConfirm.value?.message).toBe('una');

        answerConfirm(true);
        await expect(first).resolves.toBe(true);
    });

    it('clears itself so the next question can be asked', async () => {
        void confirmAction({ message: 'una' });
        answerConfirm(false);

        expect(pendingConfirm.value).toBeNull();

        const next = confirmAction({ message: 'otra' });
        answerConfirm(true);
        await expect(next).resolves.toBe(true);
    });
});
