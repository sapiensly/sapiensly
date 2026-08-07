import { describe, expect, it } from 'vitest';
import { stampLines } from './photoStamp';

/**
 * What an evidence photo carries in its corner.
 *
 * The point of the stamp is that the picture travels with its own claim about
 * WHEN and WHERE, so the two things worth locking down are that the claim is
 * never invented (a refused location writes the time alone rather than a zero
 * coordinate) and that the number written on the photo is the same number the
 * `geo` field would have stored.
 */
const AT = new Date('2026-08-07T14:32:00Z');

describe('the lines burned into a photo', () => {
    it('always says when', () => {
        const [when] = stampLines(AT, null, 'es-MX');

        expect(when).toMatch(/\d/);
    });

    it('says nothing about where when the device said nothing', () => {
        // Location off, permission refused, no fix indoors. Half a claim is
        // worth more than none, and a photo that fails because there is no
        // signal is a meter reading nobody records.
        expect(stampLines(AT, null, 'en')).toHaveLength(1);
    });

    it('writes the coordinates at the precision the record stores', () => {
        const lines = stampLines(
            AT,
            { lat: 19.432608, lng: -99.133209 },
            'es-MX',
        );

        expect(lines[1]).toBe('19.432608, -99.133209');
    });

    it('carries the accuracy the device claimed', () => {
        // "Within 8 metres" and "within 3 kilometres" are very different
        // claims about the same coordinates.
        const lines = stampLines(
            AT,
            { lat: 19.4, lng: -99.1, accuracy: 8.4 },
            'en',
        );

        expect(lines[1]).toBe('19.400000, -99.100000  ±8 m');
    });

    it('survives a locale nobody has heard of', () => {
        const lines = stampLines(AT, null, 'not-a-locale');

        expect(lines[0]).not.toBe('');
    });
});
