export interface Trend {
    dir: 'up' | 'down' | 'flat';
    good: boolean;
    label: string;
}

/**
 * Compute a trend chip from a value and its comparison (e.g. previous period).
 * `deltaGood` says which direction is positive ('up' for revenue, 'down' for
 * costs/churn) so the chip colours correctly. Returns null when there's nothing
 * to compare against.
 */
export function computeTrend(
    value: number,
    compare: number | null | undefined,
    deltaGood: 'up' | 'down' = 'up',
): Trend | null {
    if (compare === null || compare === undefined || typeof value !== 'number') return null;

    if (compare === 0) {
        if (value === 0) return { dir: 'flat', good: true, label: '0%' };
        const dir = value > 0 ? 'up' : 'down';
        return { dir, good: dir === deltaGood, label: 'nuevo' };
    }

    const change = ((value - compare) / Math.abs(compare)) * 100;
    const dir = change > 0.05 ? 'up' : change < -0.05 ? 'down' : 'flat';
    const good = dir === 'flat' ? true : dir === deltaGood;
    const sign = change > 0 ? '+' : '';
    const label = `${sign}${change.toFixed(Math.abs(change) % 1 === 0 ? 0 : 1)}%`;
    return { dir, good, label };
}
