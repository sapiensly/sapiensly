<?php

use App\Services\Records\NaturalFilterCompiler;

/**
 * A question in somebody's own words, turned into a filter the app already
 * understands.
 *
 * The model call itself has no assertion anybody can write. What CAN be tested
 * is the part that matters: nothing it returns is trusted. Every node is
 * checked against the object's own fields and the operator list, and anything
 * unrecognised throws the whole expression away rather than being pruned —
 * because a filter missing one of its conditions still returns rows, and those
 * rows look like an answer to the question that was asked.
 */
function objectForFilter(): array
{
    return [
        'id' => 'obj_pedidos0001',
        'name' => 'Pedidos',
        'slug' => 'pedidos',
        'fields' => [
            ['id' => 'fld_total00001', 'slug' => 'total', 'name' => 'Total', 'type' => 'number'],
            ['id' => 'fld_fecha00001', 'slug' => 'fecha', 'name' => 'Fecha', 'type' => 'date'],
            [
                'id' => 'fld_estado0001', 'slug' => 'estado', 'name' => 'Estado',
                'type' => 'single_select',
                'options' => [['value' => 'abierto'], ['value' => 'cerrado']],
            ],
            // Not filterable from a sentence: it takes a record id.
            ['id' => 'fld_cliente001', 'slug' => 'cliente', 'name' => 'Cliente', 'type' => 'relation', 'cardinality' => 'many_to_one', 'target_object_id' => 'obj_clientes001'],
        ],
    ];
}

function validate(array $node): bool
{
    $compiler = app(NaturalFilterCompiler::class);
    $fields = (new ReflectionMethod($compiler, 'filterableFields'))
        ->invoke($compiler, objectForFilter());

    return (new ReflectionMethod($compiler, 'isValid'))->invoke($compiler, $node, $fields);
}

it('accepts an expression built from the object’s own fields', function () {
    expect(validate([
        'op' => 'and',
        'conditions' => [
            ['op' => 'gt', 'field_id' => 'fld_total00001', 'value' => 5000],
            ['op' => 'eq', 'field_id' => 'fld_estado0001', 'value' => 'abierto'],
        ],
    ]))->toBeTrue();
});

it('refuses a field the object does not have', function () {
    // The check that keeps a phrase from reaching a column the reader was
    // never shown.
    expect(validate(['op' => 'eq', 'field_id' => 'fld_inventado01', 'value' => 1]))
        ->toBeFalse();
});

it('throws away the WHOLE expression when one condition is bad', function () {
    // Pruning the bad condition would leave a filter that still returns rows,
    // and those rows look like an answer to the question somebody asked.
    expect(validate([
        'op' => 'and',
        'conditions' => [
            ['op' => 'gt', 'field_id' => 'fld_total00001', 'value' => 5000],
            ['op' => 'eq', 'field_id' => 'fld_secreto0001', 'value' => 'x'],
        ],
    ]))->toBeFalse();
});

it('refuses an operator outside the grammar', function () {
    expect(validate(['op' => 'drop_table', 'field_id' => 'fld_total00001', 'value' => 1]))
        ->toBeFalse();
});

it('refuses a relation, which no sentence can name', function () {
    // Filtering one takes a record id; a phrase has a customer's NAME at best.
    expect(validate(['op' => 'eq', 'field_id' => 'fld_cliente001', 'value' => 'Acme']))
        ->toBeFalse();
});

it('lets a question reach when the record was created', function () {
    // Most questions are really about time: "this month", "added today".
    expect(validate([
        'op' => 'between',
        'field_id' => 'sys_created_at',
        'value' => ['2026-08-01', '2026-08-31'],
    ]))->toBeTrue();
});

it('insists on the right shape of value per operator', function () {
    expect(validate(['op' => 'in', 'field_id' => 'fld_estado0001', 'value' => 'abierto']))->toBeFalse()
        ->and(validate(['op' => 'in', 'field_id' => 'fld_estado0001', 'value' => ['abierto']]))->toBeTrue()
        // is_null needs no value at all.
        ->and(validate(['op' => 'is_null', 'field_id' => 'fld_fecha00001']))->toBeTrue()
        // …but a comparison does.
        ->and(validate(['op' => 'gt', 'field_id' => 'fld_total00001']))->toBeFalse();
});

it('refuses an expression nested past the limit', function () {
    // A cost guard: the depth is bounded before the query layer ever sees it.
    $node = ['op' => 'eq', 'field_id' => 'fld_total00001', 'value' => 1];
    for ($i = 0; $i < 6; $i++) {
        $node = ['op' => 'not', 'condition' => $node];
    }

    expect(validate($node))->toBeFalse();
});

it('does not call a model for a phrase that cannot be one', function () {
    // Empty, or long enough to be a paste of something else entirely.
    $compiler = app(NaturalFilterCompiler::class);

    expect($compiler->compile('', objectForFilter()))->toBeNull()
        ->and($compiler->compile(str_repeat('a', 400), objectForFilter()))->toBeNull();
});
