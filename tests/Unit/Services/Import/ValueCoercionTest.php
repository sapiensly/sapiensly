<?php

use App\Services\Import\ValueCoercion;

/**
 * The conversions every import depends on. Each case here is a real convention
 * a spreadsheet exports in, and getting one wrong corrupts a whole column
 * quietly — "1.234,56" read as 1.23 is still a number, so nothing complains.
 */
it('reads a number written in either convention', function (string $raw, ?float $expected) {
    expect(ValueCoercion::toNumber($raw))->toBe($expected);
})->with([
    // Both separators present: the rightmost is the decimal point.
    'spanish thousands + decimals' => ['1.234,56', 1234.56],
    'english thousands + decimals' => ['1,234.56', 1234.56],
    'millions, spanish' => ['1.234.567,89', 1234567.89],
    'millions, english' => ['1,234,567.89', 1234567.89],
    // One separator, one group of 1-2 trailing digits: a decimal point.
    'decimal comma' => ['12,5', 12.5],
    'decimal comma, two places' => ['0,99', 0.99],
    'decimal dot' => ['12.5', 12.5],
    // One separator grouping three digits: thousands.
    'thousands comma only' => ['1,234', 1234.0],
    'thousands dot only' => ['1.234', 1234.0],
    // Decoration people leave in.
    'currency symbol' => ['$1,299.00', 1299.0],
    'euro suffix' => ['1.299,00 €', 1299.0],
    'negative' => ['-42', -42.0],
    'plain integer' => ['7', 7.0],
    // Not numbers.
    'text' => ['pendiente', null],
    'empty' => ['', null],
]);

it('reads booleans in both languages, and refuses what is not one', function () {
    expect(ValueCoercion::toBoolean('sí'))->toBeTrue()
        ->and(ValueCoercion::toBoolean('SI'))->toBeTrue()
        ->and(ValueCoercion::toBoolean('true'))->toBeTrue()
        ->and(ValueCoercion::toBoolean('x'))->toBeTrue()
        ->and(ValueCoercion::toBoolean('no'))->toBeFalse()
        ->and(ValueCoercion::toBoolean('FALSO'))->toBeFalse()
        // 1/0 stays a number: a column of them is far more often a quantity.
        ->and(ValueCoercion::toBoolean('1'))->toBeNull()
        ->and(ValueCoercion::toBoolean('quizá'))->toBeNull();
});

it('reads dates, using the value itself to settle day-vs-month', function () {
    // Unambiguous: 25 cannot be a month.
    expect(ValueCoercion::toDate('25/12/2026'))->toBe('2026-12-25')
        // The same digits, read the other way round, when told to.
        ->and(ValueCoercion::toDate('03/04/2026', dayFirst: true))->toBe('2026-04-03')
        ->and(ValueCoercion::toDate('03/04/2026', dayFirst: false))->toBe('2026-03-04')
        // A day above 12 overrides the column's guess rather than producing a
        // nonsense month — evidence in the value always wins.
        ->and(ValueCoercion::toDate('25/12/2026', dayFirst: false))->toBe('2026-12-25')
        ->and(ValueCoercion::toDate('2026-12-25'))->toBe('2026-12-25')
        ->and(ValueCoercion::toDate('25-12-2026'))->toBe('2026-12-25')
        ->and(ValueCoercion::toDate('25.12.2026'))->toBe('2026-12-25')
        ->and(ValueCoercion::toDate('25/12/26'))->toBe('2026-12-25');
});

it('keeps the time when a datetime is asked for', function () {
    expect(ValueCoercion::toDate('2026-12-25 14:30:00', withTime: true))->toBe('2026-12-25T14:30:00Z')
        ->and(ValueCoercion::toDate('25/12/2026 14:30', withTime: true))->toBe('2026-12-25T14:30:00Z');
});

it('refuses a date that does not exist', function () {
    expect(ValueCoercion::toDate('31/02/2026'))->toBeNull()
        ->and(ValueCoercion::toDate('45/13/2026'))->toBeNull();
});

it('splits a multi-value cell and drops the gaps', function () {
    expect(ValueCoercion::splitList('rojo, azul , verde', ','))->toBe(['rojo', 'azul', 'verde'])
        ->and(ValueCoercion::splitList('a;;b', ';'))->toBe(['a', 'b'])
        ->and(ValueCoercion::splitList('   ', ','))->toBeNull();
});
