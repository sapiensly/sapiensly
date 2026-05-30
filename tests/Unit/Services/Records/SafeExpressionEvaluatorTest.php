<?php

use App\Services\Records\SafeExpressionEvaluator;

beforeEach(function () {
    $this->eval = new SafeExpressionEvaluator;
});

/* ---------------------------- arithmetic ---------------------------- */

it('evaluates arithmetic with operators', function () {
    expect($this->eval->evaluate('monto * 1.16', ['monto' => 1000]))->toBe(1160.0);
    expect($this->eval->evaluate('a + b - c', ['a' => 10, 'b' => 5, 'c' => 3]))->toBe(12);
    expect($this->eval->evaluate('precio * cantidad', ['precio' => 2.5, 'cantidad' => 4]))->toBe(10.0);
});

it('evaluates comparison and boolean logic', function () {
    expect($this->eval->evaluate('total > 1000', ['total' => 1500]))->toBeTrue();
    expect($this->eval->evaluate('a > 3 && b < 10', ['a' => 5, 'b' => 7]))->toBeTrue();
    expect($this->eval->evaluate('estado != "activo"', ['estado' => 'activo']))->toBeFalse();
});

it('evaluates ternaries', function () {
    expect($this->eval->evaluate('activo ? "Sí" : "No"', ['activo' => true]))->toBe('Sí');
    expect($this->eval->evaluate('n > 0 ? n : 0', ['n' => -5]))->toBe(0);
});

it('concatenates strings with ~ and concat (not +)', function () {
    expect($this->eval->evaluate('nombre ~ " " ~ apellido', ['nombre' => 'Ana', 'apellido' => 'Lopez']))->toBe('Ana Lopez');
    expect($this->eval->evaluate('concat(nombre, " ", apellido)', ['nombre' => 'Ana', 'apellido' => 'Lopez']))->toBe('Ana Lopez');
});

/* ------------------------- member access ---------------------------- */

it('reads dotted context paths (legacy syntax)', function () {
    expect($this->eval->evaluate('vars.pelicula', ['vars' => ['pelicula' => 'Matrix']]))->toBe('Matrix');
    expect($this->eval->evaluate('trigger.record.data.estado', [
        'trigger' => ['record' => ['data' => ['estado' => 'activo']]],
    ]))->toBe('activo');
});

it('returns arrays (not stdClass) for sub-object access', function () {
    expect($this->eval->evaluate('trigger.record.data', [
        'trigger' => ['record' => ['data' => ['a' => 1, 'b' => 2]]],
    ]))->toBe(['a' => 1, 'b' => 2]);
});

it('indexes into list arrays with brackets', function () {
    expect($this->eval->evaluate('vars.rows[0].data.titulo', [
        'vars' => ['rows' => [['data' => ['titulo' => 'A']], ['data' => ['titulo' => 'B']]]],
    ]))->toBe('A');
});

/* ---------------------------- functions ----------------------------- */

it('runs the ported function catalog', function () {
    expect($this->eval->evaluate('upper(s)', ['s' => 'hi']))->toBe('HI');
    expect($this->eval->evaluate('round(n, 2)', ['n' => 3.14159]))->toBe(3.14);
    expect($this->eval->evaluate('count(rows)', ['rows' => [1, 2, 3]]))->toBe(3);
    expect($this->eval->evaluate('sum(rows, "monto")', ['rows' => [['monto' => 10], ['monto' => 5]]]))->toBe(15);
    expect($this->eval->evaluate('default(missing, "fallback")', ['missing' => null]))->toBe('fallback');
});

/* ----------------------------- random ------------------------------- */

it('random() returns a float in [0, 1)', function () {
    for ($i = 0; $i < 20; $i++) {
        $v = $this->eval->evaluate('random()', []);
        expect($v)->toBeFloat()->toBeGreaterThanOrEqual(0.0)->toBeLessThan(1.0);
    }
});

it('random(min, max) returns an integer within the range', function () {
    for ($i = 0; $i < 20; $i++) {
        $v = $this->eval->evaluate('random(min, max)', ['min' => 3, 'max' => 7]);
        expect($v)->toBeInt()->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(7);
    }
});

it('random(array) still picks an element', function () {
    expect($this->eval->evaluate('random(items)', ['items' => ['a', 'b', 'c']]))->toBeIn(['a', 'b', 'c']);
});

it('evaluates a random-number-in-range formula end to end', function () {
    $expr = 'round(random() * (form.max - form.min) + form.min)';
    for ($i = 0; $i < 20; $i++) {
        $v = $this->eval->evaluate($expr, ['form' => ['min' => 1, 'max' => 10]]);
        expect($v)->toBeGreaterThanOrEqual(1.0)->toBeLessThanOrEqual(10.0);
    }
});

/* --------------------------- robustness ----------------------------- */

it('throws on unknown functions so callers can fall back', function () {
    expect(fn () => $this->eval->evaluate('bogus(x)', ['x' => 1]))->toThrow(Exception::class);
});

it('does not allow calling arbitrary PHP', function () {
    expect(fn () => $this->eval->evaluate('phpinfo()', []))->toThrow(Exception::class);
    expect(fn () => $this->eval->evaluate('system("ls")', []))->toThrow(Exception::class);
});
