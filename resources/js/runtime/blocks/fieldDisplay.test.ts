import { describe, expect, it } from 'vitest';
import type { FieldDef, FieldType } from '../types/manifest';
import {
    EMPTY_MARK,
    formatFieldValue,
    type DisplayContext,
} from './fieldDisplay';

/**
 * How a stored value becomes the thing a person reads.
 *
 * This is the one piece of the runtime that is pure logic and was, until now,
 * only ever exercised by rendering a whole app in a browser — which is how a
 * bare date came to be read a day early everywhere west of Greenwich for
 * months. A test that runs in a different timezone catches that in a second;
 * a screenshot taken in UTC never does.
 */
const ctx = (over: Partial<DisplayContext> = {}): DisplayContext => ({
    locale: 'es-MX',
    defaultCurrency: 'MXN',
    ...over,
});

const field = (over: Partial<FieldDef>): FieldDef =>
    ({
        id: 'fld_1',
        slug: 'x',
        name: 'X',
        type: 'string',
        ...over,
    }) as FieldDef;

describe('a value with nothing in it', () => {
    // Annotated: a bare array of tuples widens each type to `string`.
    it.each<[FieldType, unknown]>([
        ['string', null],
        ['number', undefined],
        ['currency', ''],
        ['multi_select', []],
    ])('shows the absence mark for a blank %s', (type, value) => {
        expect(formatFieldValue(field({ type }), value, ctx())).toBe(
            EMPTY_MARK,
        );
    });

    it('does not mistake zero for absence', () => {
        // A quantity of zero is a fact. Reading it as "no value" is how a
        // stock count of 0 disappears from a screen that exists to show it.
        expect(formatFieldValue(field({ type: 'number' }), 0, ctx())).not.toBe(
            EMPTY_MARK,
        );
    });

    it('does not mistake false for absence', () => {
        expect(
            formatFieldValue(field({ type: 'boolean' }), false, ctx()),
        ).not.toBe(EMPTY_MARK);
    });
});

describe('a date', () => {
    it('shows the day it says, not the day it is in UTC', () => {
        // The defect this pins: `2024-06-01` was parsed as midnight UTC and
        // then printed in the reader's zone, so everyone west of Greenwich saw
        // "31 may". A bare date has no time and no zone — it is the day it
        // says, everywhere.
        const out = formatFieldValue(
            field({ type: 'date' }),
            '2024-06-01',
            ctx(),
        );

        expect(out).toContain('1');
        expect(out).not.toContain('31');
    });

    it('follows the app locale rather than the machine', () => {
        const es = formatFieldValue(
            field({ type: 'date' }),
            '2024-06-01',
            ctx(),
        );
        const en = formatFieldValue(
            field({ type: 'date' }),
            '2024-06-01',
            ctx({ locale: 'en-US' }),
        );

        expect(es).not.toBe(en);
    });
});

describe('money', () => {
    it('uses the field own currency over the app default', () => {
        // An app that bills in pesos can hold a supplier price in dollars.
        // Falling back to the app currency would relabel it, silently.
        const out = formatFieldValue(
            field({
                type: 'currency',
                currency_code: 'USD',
            } as Partial<FieldDef>),
            1234.5,
            ctx(),
        );

        expect(out).toContain('1,234.50');
    });

    it('groups thousands the way the locale does', () => {
        const mx = formatFieldValue(
            field({ type: 'currency' }),
            168500,
            ctx({ locale: 'es-MX' }),
        );

        // es-MX groups with a comma. A dot here would read as 168.5 to the
        // person the app was built for.
        expect(mx).toContain('168,500');
    });
});

describe('a relation', () => {
    it('shows the record it points at, not the id it stores', () => {
        const out = formatFieldValue(
            field({ type: 'relation', slug: 'cliente' }),
            'rec_01k9999',
            ctx({ labels: { cliente: 'Ferretería del Norte' } }),
        );

        expect(out).toBe('Ferretería del Norte');
    });

    it('says nothing rather than showing an id it could not resolve', () => {
        const out = formatFieldValue(
            field({ type: 'relation', slug: 'cliente' }),
            'rec_01k9999',
            ctx(),
        );

        expect(out).not.toContain('rec_');
    });
});
