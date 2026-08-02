import { describe, expect, it } from 'vitest';
import { imageHref } from './imageValue';

/**
 * A product card came out with a hundred and twenty eight pixels of hole above
 * its name: the image field held "Benchmark foto" — a sentence, not an address
 * — and `<img src>` reserved the picture's height to paint a broken box.
 *
 * The field is a plain text box. People type descriptions into those.
 */
describe('a value offered as an image', () => {
    it.each([
        'https://cdn.example.test/plato.jpg',
        'http://example.test/a.png',
        '/storage/uploads/plato.webp',
        'data:image/png;base64,iVBORw0KGgo=',
    ])('loads what a browser can fetch: %s', (src) => {
        expect(imageHref(src)).toBe(src);
    });

    it.each([
        ['a description', 'Benchmark foto'],
        ['a sentence', 'foto del salón principal'],
        ['a bare filename', 'plato.jpg'],
        ['a relative path', 'images/plato.jpg'],
        ['empty', ''],
        ['whitespace', '   '],
    ])('shows no picture rather than a hole for %s', (_, src) => {
        expect(imageHref(src)).toBeNull();
    });

    it('is not fooled by a non-string', () => {
        expect(imageHref(null)).toBeNull();
        expect(imageHref(42)).toBeNull();
        expect(imageHref({ url: 'https://example.test/a.png' })).toBeNull();
    });

    it('trims, because a pasted URL usually arrives with a newline', () => {
        expect(imageHref('  https://example.test/a.png\n')).toBe(
            'https://example.test/a.png',
        );
    });
});
