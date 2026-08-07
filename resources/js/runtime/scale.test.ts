import { describe, expect, it } from 'vitest';
import { parseWeight } from './scale';

/**
 * The number inside a scale's chatter.
 *
 * Every device in this family sends a line of text several times a second, and
 * they disagree about nearly everything except that the reading is in there
 * somewhere. What must NOT be lenient is the unstable marker: a weight read
 * while the pan is still moving is the wrong weight, written down with total
 * confidence, on somebody's invoice.
 */
describe('reading a line off a scale', () => {
    it('reads the plain frames', () => {
        expect(parseWeight('ST,GS,+  1.234kg')).toBe(1.234);
        expect(parseWeight('+0001.234 kg')).toBe(1.234);
        expect(parseWeight('  12.5 ')).toBe(12.5);
        expect(parseWeight('GS 0.000 kg')).toBe(0);
    });

    it('reads a comma as a decimal point', () => {
        // Half the scales sold in Spanish- and Portuguese-speaking markets.
        expect(parseWeight('ST,GS,+  1,234kg')).toBe(1.234);
    });

    it('takes the number the reading is, not the one the status is', () => {
        // These frames lead with status words and trail with a unit, so any
        // leading digits belong to the status.
        expect(parseWeight('S S       1.500 kg')).toBe(1.5);
        expect(parseWeight('02ST 3.75kg')).toBe(3.75);
    });

    it('refuses a reading the device says is still moving', () => {
        expect(parseWeight('US,GS,+  1.234kg')).toBeNull();
        expect(parseWeight('us gs 1.234 kg')).toBeNull();
        expect(parseWeight('? 1.234 kg')).toBeNull();
    });

    it('does not mistake a stable frame for an unstable one', () => {
        // `US` as part of a longer word is not the marker — refusing every
        // frame would make the button look broken rather than careful.
        expect(parseWeight('STATUS 1.234 kg')).toBe(1.234);
    });

    it('has nothing to say about a line with no number', () => {
        expect(parseWeight('')).toBeNull();
        expect(parseWeight('   ')).toBeNull();
        expect(parseWeight('OVERLOAD')).toBeNull();
    });

    it('keeps a negative reading', () => {
        // A tared scale really can read below zero, and swallowing the sign
        // would store the opposite of what the machine said.
        expect(parseWeight('ST,NT,-  0.250kg')).toBe(-0.25);
    });
});
