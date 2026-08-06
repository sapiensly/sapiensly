import { afterEach, describe, expect, it, vi } from 'vitest';
import { describeAge } from './useOfflineStatus';

/**
 * How old the data on screen is, in words.
 *
 * The bar's whole job is to stop somebody acting on a snapshot as if it were
 * live, so the string it prints is load-bearing: coarse enough to be honest,
 * never absent when we know the answer, and never invented when we do not.
 */
const NOW = new Date('2026-08-06T12:00:00Z');

const ago = (minutes: number) => new Date(NOW.getTime() - minutes * 60_000);

afterEach(() => {
    vi.useRealTimers();
});

function at(now: Date): void {
    vi.useFakeTimers();
    vi.setSystemTime(now);
}

describe('describing the age of cached data', () => {
    it('says nothing when there is nothing to say', () => {
        // A browser that refuses Cache Storage still gets the offline bar,
        // just without a claim about freshness we cannot back up.
        expect(describeAge(null, 'es')).toBeNull();
    });

    it('coarsens as it gets older', () => {
        at(NOW);

        expect(describeAge(ago(0), 'en')).toBe('just now');
        expect(describeAge(ago(7), 'en')).toBe('7 min ago');
        expect(describeAge(ago(200), 'en')).toBe('3 h ago');
        expect(describeAge(ago(60 * 50), 'en')).toBe('2 d ago');
    });

    it('speaks the app’s language', () => {
        at(NOW);

        expect(describeAge(ago(0), 'es')).toBe('hace un momento');
        expect(describeAge(ago(7), 'es')).toBe('hace 7 min');
        expect(describeAge(ago(200), 'es')).toBe('hace 3 h');
        expect(describeAge(ago(60 * 50), 'es')).toBe('hace 2 d');
        expect(describeAge(ago(7), 'es-MX')).toBe('hace 7 min');
    });

    it('never reports a negative age', () => {
        // A device clock behind the server's would otherwise produce "hace -4
        // min", which reads as a bug in the data rather than in the clock.
        at(NOW);

        expect(describeAge(new Date(NOW.getTime() + 4 * 60_000), 'es')).toBe('hace un momento');
    });
});
