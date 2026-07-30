<?php

use App\Services\Import\ColumnProfiler;

/**
 * Type inference is what makes an import worth doing: a "Precio" column that
 * lands as `currency` can be summed and charted; the same column as `string`
 * cannot, and nobody goes back to retype twenty of them.
 *
 * The governing rule is unanimity — a type is claimed only when EVERY value
 * converts. A majority vote would corrupt the minority silently, which is worse
 * than a column the user retypes once.
 */
function profileOf(string $header, array $values)
{
    return (new ColumnProfiler)->profile($header, $values);
}

/** A column long enough to clear the select heuristic's minimum-rows floor. */
function repeated(array $values, int $times = 3): array
{
    return array_merge(...array_fill(0, $times, $values));
}

it('types a money column as currency, from the header or the symbol', function () {
    expect(profileOf('Precio', ['1.200,50', '900,00', '15'])->type)->toBe('currency')
        ->and(profileOf('Total', ['10', '20'])->type)->toBe('currency')
        // No money word in the name — the symbol in the values settles it.
        ->and(profileOf('Valor', ['$10.00', '$20.00'])->type)->toBe('currency')
        // Plain counts stay numbers.
        ->and(profileOf('Cantidad', ['1', '2', '3'])->type)->toBe('number');
});

it('types dates, and reports how it read an ambiguous one', function () {
    expect(profileOf('Fecha', ['25/12/2026', '01/03/2026'])->type)->toBe('date')
        ->and(profileOf('Creado', ['2026-01-15 10:00', '2026-02-01 11:30'])->type)->toBe('datetime');

    // Every value fits both readings: the profiler must say so rather than
    // silently picking one, because the file genuinely cannot tell us.
    $ambiguous = profileOf('Fecha', ['03/04/2026', '05/06/2026']);
    expect($ambiguous->dayFirst)->toBeTrue()
        ->and($ambiguous->notes)->not->toBeEmpty();

    // A value above 12 in the second position proves month-first.
    $monthFirst = profileOf('Fecha', ['03/25/2026', '01/02/2026']);
    expect($monthFirst->dayFirst)->toBeFalse();
});

it('does not mistake a year column for a date', function () {
    expect(profileOf('Año', ['2024', '2025', '2026'])->type)->toBe('number');
});

it('types contact columns', function () {
    expect(profileOf('Email', ['a@example.com', 'b@example.com'])->type)->toBe('email')
        ->and(profileOf('Sitio', ['https://acme.com', 'http://globex.io'])->type)->toBe('url')
        ->and(profileOf('Teléfono', ['+52 55 1234 5678', '+34 600 123 456'])->type)->toBe('phone');
});

it('keeps a column as text when a single value does not fit', function () {
    // One non-email in an otherwise perfect email column: text, not email —
    // typing it as email would reject that row at import.
    expect(profileOf('Email', ['a@example.com', 'sin correo'])->type)->toBe('string')
        ->and(profileOf('Precio', ['10', '20', 'a consultar'])->type)->toBe('string');
});

it('turns a small repeating vocabulary into a choice list', function () {
    $profile = profileOf('Estado', repeated(['activo', 'pausado', 'cancelado'], 4));

    expect($profile->type)->toBe('single_select')
        ->and($profile->options)->toHaveCount(3)
        ->and(collect($profile->options)->pluck('value')->all())
        ->toBe(['activo', 'cancelado', 'pausado']);
});

it('leaves a column of mostly-unique values as text, not a 40-option list', function () {
    $names = array_map(fn (int $i): string => "Cliente {$i}", range(1, 20));

    expect(profileOf('Cliente', $names)->type)->toBe('string');
});

it('detects a cell holding several values', function () {
    $profile = profileOf('Etiquetas', repeated(['rojo, azul', 'verde', 'rojo, verde'], 4));

    expect($profile->type)->toBe('multi_select')
        ->and($profile->listSeparator)->toBe(',')
        ->and(collect($profile->options)->pluck('value')->all())->toBe(['azul', 'rojo', 'verde']);
});

it('types a paragraph column as long text', function () {
    $long = str_repeat('Una descripción larga del producto. ', 6);

    expect(profileOf('Descripción', [$long, 'corta'])->type)->toBe('long_text');
});

it('types booleans written as words', function () {
    expect(profileOf('Activo', repeated(['sí', 'no'], 5))->type)->toBe('boolean');
});

it('falls back to text for an empty column, and says why', function () {
    $profile = profileOf('Notas', [null, null, null]);

    expect($profile->type)->toBe('string')
        ->and($profile->filled)->toBe(0)
        ->and($profile->notes)->not->toBeEmpty();
});
