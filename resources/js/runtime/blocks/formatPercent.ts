/**
 * Format a KPI value as a percentage, honouring the backend's `value_scale`.
 *
 * A `ratio_denominator` KPI resolves to a 0..1 fraction (scale 'fraction'), which
 * Intl's `style:'percent'` renders by multiplying ×100 → "85%". A plain aggregate of
 * a column that already stores a per-row percentage (scale 'unit', e.g. avg(otd_pct)
 * = 94.6) must NOT be multiplied again, or it prints "9,460%". Same `format:"percentage"`,
 * two scales — this is the one place that reconciles them so every KPI block agrees.
 */
export function formatPercent(
    value: number,
    scale: 'fraction' | 'unit' | undefined,
    locale: string,
): string {
    if (scale === 'unit') {
        return (
            new Intl.NumberFormat(locale, { maximumFractionDigits: 1 }).format(
                value,
            ) + '%'
        );
    }
    // Default (and 'fraction'): a 0..1 ratio → ×100.
    return new Intl.NumberFormat(locale, {
        style: 'percent',
        maximumFractionDigits: 1,
    }).format(value);
}
