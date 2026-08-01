import type { FieldDef, ObjectDef } from '../types/manifest';

/**
 * How a stored value is shown to a person — one implementation, shared.
 *
 * The table, the record detail, the related list and the kanban card each grew
 * their own near-identical `format()`, and they drifted: none of them knew what
 * to do with a `relation`, so a column pointing at another record printed its
 * raw `rec_01k…` id, and only the kanban ever used the colour an option
 * carries. Anything a component wants to render a field with now comes from
 * here, so that cannot happen a fifth time.
 */
export interface DisplayContext {
    locale: string;
    defaultCurrency: string;
    /**
     * Display text for the record a relation field points at, keyed by field
     * slug. Resolved server-side (the browser only ever receives the id) and
     * absent when the block does not show relations.
     */
    labels?: Record<string, unknown>;
    /**
     * Every object in the manifest, so a derived field can be shown the way the
     * field it derives from would be. Optional: without it a rollup still
     * renders, just as a plain number.
     */
    objects?: ObjectDef[];
}

/** One option of a select, with the colour the manifest gave it. */
export interface ValueChip {
    label: string;
    color?: string;
}

const EMPTY = '—';

function isBlank(value: unknown): boolean {
    return (
        value === null ||
        value === undefined ||
        value === '' ||
        (Array.isArray(value) && value.length === 0)
    );
}

/**
 * The field a rollup or lookup draws from, searched across every object because
 * a derived field always points at another object's column.
 */
function sourceField(
    field: FieldDef,
    objects?: ObjectDef[],
): FieldDef | undefined {
    const target = field.target_field_id;
    if (target === undefined || objects === undefined) return undefined;

    for (const object of objects) {
        const found = object.fields.find((f) => f.id === target);
        if (found !== undefined) return found;
    }

    return undefined;
}

/** Look an option up by its stored value, falling back to the raw value. */
function optionFor(field: FieldDef, value: unknown): ValueChip {
    const option = field.options?.find((o) => o.value === value);
    return { label: option?.label ?? String(value), color: option?.color };
}

/**
 * The chips a select-like field renders as, or null when this field is not one.
 *
 * Returned rather than drawn so the caller keeps control of size and density: a
 * kanban card and a table cell want the same colours at different scales.
 */
export function valueChips(
    field: FieldDef,
    value: unknown,
): ValueChip[] | null {
    if (isBlank(value)) return null;
    if (field.type === 'single_select') return [optionFor(field, value)];
    if (field.type === 'multi_select' && Array.isArray(value)) {
        return value.map((v) => optionFor(field, v));
    }
    return null;
}

/**
 * The plain-text form of a value: what goes in a cell that is not a chip, and
 * what a CSV export or a title attribute reads.
 */
export function formatFieldValue(
    field: FieldDef,
    value: unknown,
    ctx: DisplayContext,
): string {
    // A relation resolves to the record it points at. Without the server-side
    // label there is nothing human to show — the id is noise, not information,
    // so say "set" rather than print it.
    if (field.type === 'relation') {
        const label = ctx.labels?.[field.slug];
        if (typeof label === 'string' && label !== '') return label;
        return isBlank(value) ? EMPTY : '—';
    }

    if (isBlank(value)) return EMPTY;

    // A derived field is shown the way its source would be. A rollup that SUMS
    // a currency is money and has to read as money: "37000" sat in the column
    // beside "$37,000.00", the same quantity twice, formatted two ways. A count
    // is the exception — it counts rows, so it is a plain number whatever it
    // counted.
    if (field.type === 'rollup' || field.type === 'lookup') {
        const source =
            field.aggregator === 'count'
                ? undefined
                : sourceField(field, ctx.objects);

        if (source !== undefined) {
            return formatFieldValue(
                { ...source, name: field.name, slug: field.slug },
                value,
                { ...ctx, labels: undefined },
            );
        }

        return typeof value === 'number'
            ? new Intl.NumberFormat(ctx.locale).format(value)
            : String(value);
    }

    if (field.type === 'formula') {
        // A formula declares what it returns; a money one carries its currency.
        if (field.return_type === 'number' && field.currency_code) {
            return new Intl.NumberFormat(ctx.locale, {
                style: 'currency',
                currency: field.currency_code,
            }).format(Number(value));
        }

        return typeof value === 'number'
            ? new Intl.NumberFormat(ctx.locale).format(value)
            : String(value);
    }

    if (field.type === 'currency' && typeof value === 'number') {
        return new Intl.NumberFormat(ctx.locale, {
            style: 'currency',
            currency: field.currency_code ?? ctx.defaultCurrency ?? 'MXN',
        }).format(value);
    }

    if (field.type === 'number' && typeof value === 'number') {
        return new Intl.NumberFormat(ctx.locale).format(value);
    }

    if (field.type === 'boolean') return value ? '✓' : EMPTY;

    if (field.type === 'single_select' || field.type === 'multi_select') {
        return (valueChips(field, value) ?? []).map((c) => c.label).join(', ');
    }

    if (field.type === 'date' || field.type === 'datetime') {
        return formatDate(value, field.type === 'datetime', ctx.locale);
    }

    if (field.type === 'rating') {
        const n = Number(value);
        const max = field.max ?? 5;
        const icon =
            field.icon === 'heart' ? '♥' : field.icon === 'thumb' ? '👍' : '★';
        return (
            icon.repeat(Math.max(0, Math.min(max, Math.round(n)))) +
            ` ${n}/${max}`
        );
    }

    if (field.type === 'slider') {
        const n = Number(value);
        if (field.format === 'percentage') return `${n}%`;
        if (field.format === 'currency') {
            try {
                return new Intl.NumberFormat(ctx.locale, {
                    style: 'currency',
                    currency: field.currency_code ?? ctx.defaultCurrency,
                }).format(n);
            } catch {
                return String(n);
            }
        }
        return new Intl.NumberFormat(ctx.locale).format(n);
    }

    if (field.type === 'date_range' && typeof value === 'object') {
        const range = value as { from?: string; to?: string };
        const from = range.from
            ? formatDate(range.from, false, ctx.locale)
            : EMPTY;
        const to = range.to ? formatDate(range.to, false, ctx.locale) : EMPTY;
        return `${from} → ${to}`;
    }

    if (field.type === 'file' && typeof value === 'object') {
        return (value as { original_name?: string }).original_name ?? 'file';
    }

    return String(value);
}

/**
 * A stored date rendered in the viewer's locale.
 *
 * The store keeps a naive "YYYY-MM-DD HH:MM:SS", which browsers are free to
 * read as UTC — so a reply written at 09:15 came back as 03:15 for a reader six
 * hours west of it. Normalising the separator makes it parse as LOCAL time,
 * which is the wall clock whoever typed it meant.
 */
export function formatDate(
    value: unknown,
    withTime: boolean,
    locale: string,
): string {
    const raw = String(value).trim();

    // A bare "YYYY-MM-DD" is a CALENDAR DAY, not an instant. new Date() reads
    // it as midnight UTC, and rendering that anywhere west of Greenwich moves
    // it back a day: a lease starting 2024-06-01 displayed as 31 may 2024 for
    // every reader in the Americas. Built from its parts it stays the day it
    // says, in any zone.
    const day = /^(\d{4})-(\d{2})-(\d{2})$/.exec(raw);
    if (day !== null) {
        const local = new Date(
            Number(day[1]),
            Number(day[2]) - 1,
            Number(day[3]),
        );

        return Number.isNaN(local.getTime())
            ? raw
            : local.toLocaleDateString(locale, { dateStyle: 'medium' });
    }

    // A datetime with no offset is a wall clock too — the same reasoning the
    // write path applies — so it is read in the reader's own zone rather than
    // as UTC.
    const parsed = new Date(
        /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/.test(raw) &&
            !/[zZ]|[+-]\d{2}:?\d{2}$/.test(raw)
            ? raw.replace(' ', 'T')
            : raw,
    );
    if (Number.isNaN(parsed.getTime())) return raw;

    return withTime
        ? parsed.toLocaleString(locale, {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : parsed.toLocaleDateString(locale, { dateStyle: 'medium' });
}
