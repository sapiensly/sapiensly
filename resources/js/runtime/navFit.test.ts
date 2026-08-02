import { describe, expect, it } from 'vitest';
import { fitCount } from './navFit';

const opts = { overflowWidth: 90, gap: 4 };

describe('fitting a row of links', () => {
    it('shows everything when everything fits', () => {
        expect(fitCount([80, 80, 80], 400, opts)).toBe(3);
    });

    it('shows everything when the row is exactly full', () => {
        // 80 + 4 + 80 + 4 + 80 = 248. No overflow control is needed, so none
        // of its width should be reserved — an off-by-one here folds a link
        // away for no reason.
        expect(fitCount([80, 80, 80], 248, opts)).toBe(3);
    });

    it('makes room for the control that holds what it dropped', () => {
        // 250 fits all three raw, but at 240 it does not: what remains has to
        // share the row with a 90px "more", leaving 146 — one link and a bit.
        expect(fitCount([80, 80, 80], 240, opts)).toBe(1);
    });

    it('can fold everything away when there is room for nothing', () => {
        expect(fitCount([120, 120], 100, opts)).toBe(0);
    });

    it('measures each item rather than assuming they are equal', () => {
        // The reason a breakpoint is the wrong tool: five short names fit
        // where two long ones do not.
        expect(fitCount([40, 40, 40, 40, 40], 300, opts)).toBe(5);
        expect(fitCount([200, 200], 300, opts)).toBe(1);
    });
});

describe('before anything has been measured', () => {
    it('shows everything rather than folding on a guess', () => {
        // A zero width is an unlaid row, not a narrow one. Folding here hides
        // links that nothing later brings back — which is exactly how an
        // earlier attempt clipped destinations out of existence.
        expect(fitCount([0, 0, 0], 200, opts)).toBe(3);
        expect(fitCount([80, 0, 80], 200, opts)).toBe(3);
    });

    it('shows everything when the room is not known yet', () => {
        expect(fitCount([80, 80], Number.POSITIVE_INFINITY, opts)).toBe(2);
        expect(fitCount([80, 80], 0, opts)).toBe(2);
    });

    it('has nothing to show when there is nothing', () => {
        expect(fitCount([], 500, opts)).toBe(0);
    });
});
