<?php

use App\Services\Records\ExpressionResolver;
use App\Services\Records\SafeExpressionEvaluator;

beforeEach(function () {
    $this->resolver = new ExpressionResolver(new SafeExpressionEvaluator);
});

/* -------------- Backwards-compat: pre-function syntax ------------ */

it('returns literal strings untouched', function () {
    expect($this->resolver->resolve('hola', []))->toBe('hola');
});

it('resolves a dotted context path', function () {
    expect($this->resolver->resolve('{{vars.pelicula}}', ['vars' => ['pelicula' => 'Matrix']]))->toBe('Matrix');
});

it('returns null when the path is missing', function () {
    expect($this->resolver->resolve('{{vars.nope}}', ['vars' => []]))->toBeNull();
});

it('still resolves steps.<id>.output.<path>', function () {
    expect($this->resolver->resolve(
        '{{steps.stp_xyz.output.rows.0.data.titulo}}',
        ['steps' => ['stp_xyz' => ['output' => ['rows' => [['data' => ['titulo' => 'Pulp Fiction']]]]]]],
    ))->toBe('Pulp Fiction');
});

/* -------------- expression engine: operators ------------ */

it('evaluates arithmetic inside a token', function () {
    expect($this->resolver->resolve('{{vars.monto * 1.16}}', ['vars' => ['monto' => 1000]]))->toBe(1160.0);
});

it('evaluates comparison and boolean operators', function () {
    expect($this->resolver->resolve('{{vars.total > 1000}}', ['vars' => ['total' => 1500]]))->toBeTrue();
    expect($this->resolver->resolve('{{vars.a > 3 && vars.b < 10}}', ['vars' => ['a' => 5, 'b' => 7]]))->toBeTrue();
});

it('evaluates a ternary', function () {
    expect($this->resolver->resolve('{{vars.activo ? "Sí" : "No"}}', ['vars' => ['activo' => false]]))->toBe('No');
});

it('falls back to the legacy walker for numeric dotted indices', function () {
    expect($this->resolver->resolve(
        '{{vars.rows.0.data.titulo}}',
        ['vars' => ['rows' => [['data' => ['titulo' => 'Matrix']]]]],
    ))->toBe('Matrix');
});

/* -------------- now() / today() ------------ */

it('now() returns an ISO 8601 string', function () {
    $value = $this->resolver->resolve('{{now()}}', []);
    expect($value)->toBeString()
        ->and($value)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+\d{2}:\d{2}$/');
});

it('now(format) returns the formatted current datetime', function () {
    $value = $this->resolver->resolve("{{now('Y-m-d')}}", []);
    expect($value)->toMatch('/^\d{4}-\d{2}-\d{2}$/');
});

it('today() returns YYYY-MM-DD', function () {
    expect($this->resolver->resolve('{{today()}}', []))->toMatch('/^\d{4}-\d{2}-\d{2}$/');
});

/* -------------- random() — the original use case ------------ */

it('random(arr) returns one of the array elements', function () {
    $context = ['vars' => ['lista' => ['a', 'b', 'c']]];
    for ($i = 0; $i < 20; $i++) {
        $pick = $this->resolver->resolve('{{random(vars.lista)}}', $context);
        expect($pick)->toBeIn(['a', 'b', 'c']);
    }
});

it('random() over an empty array returns null', function () {
    expect($this->resolver->resolve('{{random(vars.lista)}}', ['vars' => ['lista' => []]]))->toBeNull();
});

it('chained access works on function return value (random + .field)', function () {
    $context = ['vars' => ['peliculas' => [
        ['data' => ['titulo' => 'A']],
        ['data' => ['titulo' => 'B']],
    ]]];
    $pick = $this->resolver->resolve('{{random(vars.peliculas).data.titulo}}', $context);
    expect($pick)->toBeIn(['A', 'B']);
});

/* -------------- count / length / first / last / slice ------------ */

it('count(arr) returns the array length', function () {
    expect($this->resolver->resolve('{{count(vars.lista)}}', ['vars' => ['lista' => [1, 2, 3]]]))->toBe(3);
});

it('count(string) returns the string length', function () {
    expect($this->resolver->resolve('{{count(vars.s)}}', ['vars' => ['s' => 'hola']]))->toBe(4);
});

it('length is an alias for count', function () {
    expect($this->resolver->resolve('{{length(vars.s)}}', ['vars' => ['s' => 'hola']]))->toBe(4);
});

it('first(arr) returns the first element', function () {
    expect($this->resolver->resolve('{{first(vars.lista)}}', ['vars' => ['lista' => ['a', 'b']]]))->toBe('a');
});

it('last(arr) returns the last element', function () {
    expect($this->resolver->resolve('{{last(vars.lista)}}', ['vars' => ['lista' => ['a', 'b', 'c']]]))->toBe('c');
});

it('slice(arr, n) returns the first n elements', function () {
    expect($this->resolver->resolve('{{slice(vars.lista, 2)}}', ['vars' => ['lista' => [1, 2, 3, 4]]]))->toBe([1, 2]);
});

/* -------------- pluck / aggregates ------------ */

it('pluck(arr, "field") returns a list of dotted values', function () {
    $context = ['vars' => ['rows' => [
        ['data' => ['precio' => 100]],
        ['data' => ['precio' => 200]],
    ]]];
    expect($this->resolver->resolve("{{pluck(vars.rows, 'data.precio')}}", $context))->toBe([100, 200]);
});

it('sum aggregates numeric values, optionally plucking a field', function () {
    $context = ['vars' => ['rows' => [
        ['data' => ['precio' => 100]],
        ['data' => ['precio' => 250]],
    ]]];
    expect($this->resolver->resolve("{{sum(vars.rows, 'data.precio')}}", $context))->toBe(350);
});

it('min/max/avg work the same way', function () {
    $context = ['vars' => ['rows' => [['n' => 10], ['n' => 30], ['n' => 50]]]];
    expect($this->resolver->resolve("{{min(vars.rows, 'n')}}", $context))->toBe(10)
        ->and($this->resolver->resolve("{{max(vars.rows, 'n')}}", $context))->toBe(50)
        ->and($this->resolver->resolve("{{avg(vars.rows, 'n')}}", $context))->toBe(30.0);
});

it('aggregates return null on an empty array', function () {
    expect($this->resolver->resolve('{{sum(vars.rows)}}', ['vars' => ['rows' => []]]))->toBeNull();
});

/* -------------- string helpers + concat + default ------------ */

it('upper/lower transform strings', function () {
    expect($this->resolver->resolve("{{upper('hola')}}", []))->toBe('HOLA')
        ->and($this->resolver->resolve("{{lower('HOLA')}}", []))->toBe('hola');
});

it('concat joins its args as a string', function () {
    expect($this->resolver->resolve("{{concat('Hola, ', vars.nombre, '!')}}", ['vars' => ['nombre' => 'Ana']]))
        ->toBe('Hola, Ana!');
});

it('default falls back to the second arg when the first is null', function () {
    expect($this->resolver->resolve("{{default(vars.missing, 'fallback')}}", ['vars' => []]))
        ->toBe('fallback');
});

/* -------------- robustness ------------ */

it('unknown functions return null', function () {
    expect($this->resolver->resolve('{{wat(1)}}', []))->toBeNull();
});

it('numeric literals parse as int / float', function () {
    expect($this->resolver->resolve('{{slice(vars.l, 2)}}', ['vars' => ['l' => [10, 20, 30]]]))->toBe([10, 20]);
});

it('malformed parens degrade to null instead of throwing', function () {
    // Unbalanced parens INSIDE a valid {{ … }} envelope — must not throw.
    expect($this->resolver->resolve('{{random(vars.l}}', ['vars' => ['l' => [1]]]))->toBeNull();
});

/* -------------- mixed templates (embedded tokens) ------------ */

it('interpolates a token embedded in surrounding text', function () {
    expect($this->resolver->resolve(
        'Return RMA {{trigger.rma}}, reason: {{trigger.reason}}.',
        ['trigger' => ['rma' => 'RMA-2042', 'reason' => 'defective']],
    ))->toBe('Return RMA RMA-2042, reason: defective.');
});

it('interpolates a step output inside a message', function () {
    expect($this->resolver->resolve(
        'Drafted reply: {{steps.s1.output.text}}',
        ['steps' => ['s1' => ['output' => ['text' => 'Hello there']]]],
    ))->toBe('Drafted reply: Hello there');
});

it('keeps a whole-string single token TYPED (not stringified)', function () {
    expect($this->resolver->resolve('{{vars.monto * 2}}', ['vars' => ['monto' => 21]]))->toBe(42);
});

it('renders an unresolved embedded token as empty, not the raw braces', function () {
    expect($this->resolver->resolve('Hi {{vars.missing}}!', ['vars' => []]))->toBe('Hi !');
});

it('leaves a string without tokens untouched even if it has braces-like text', function () {
    expect($this->resolver->resolve('100% off, no tokens here', []))->toBe('100% off, no tokens here');
});

it('interpolates a numeric token into a string', function () {
    expect($this->resolver->resolve('Total: {{vars.n}}', ['vars' => ['n' => 1160.5]]))->toBe('Total: 1160.5');
});

/* -------------- range_start() — date-range preset filter ------------ */

it('range_start maps preset keys to a window-start date', function () {
    expect($this->resolver->resolve("{{range_start('today')}}", []))
        ->toBe(now()->utc()->toDateString());
    expect($this->resolver->resolve("{{range_start('7d')}}", []))
        ->toBe(now()->utc()->subDays(7)->toDateString());
    expect($this->resolver->resolve("{{range_start('30d')}}", []))
        ->toBe(now()->utc()->subDays(30)->toDateString());
    expect($this->resolver->resolve("{{range_start('90d')}}", []))
        ->toBe(now()->utc()->subDays(90)->toDateString());
    expect($this->resolver->resolve("{{range_start('1y')}}", []))
        ->toBe(now()->utc()->subYear()->toDateString());
});

it("range_start returns '' for 'all' and unknown keys (filter is skipped)", function () {
    expect($this->resolver->resolve("{{range_start('all')}}", []))->toBe('');
    expect($this->resolver->resolve("{{range_start('nope')}}", []))->toBe('');
});

it('range_start(default(params.range, ...)) applies the preselected window when the param is unset', function () {
    expect($this->resolver->resolve("{{range_start(default(params.range, '30d'))}}", ['params' => []]))
        ->toBe(now()->utc()->subDays(30)->toDateString());
});

it('range_start(default(params.range, ...)) honours the chosen preset over the default', function () {
    expect($this->resolver->resolve("{{range_start(default(params.range, '30d'))}}", ['params' => ['range' => '7d']]))
        ->toBe(now()->utc()->subDays(7)->toDateString());
    expect($this->resolver->resolve("{{range_start(default(params.range, '30d'))}}", ['params' => ['range' => 'all']]))
        ->toBe('');
});

it('range_prev_start brackets the previous window of each preset', function () {
    // With range_start as the exclusive end, [range_prev_start, range_start)
    // is the period-over-period compare window KPI delta chips read.
    expect($this->resolver->resolve("{{range_prev_start('30d')}}", []))
        ->toBe(now()->utc()->subDays(60)->toDateString());
    expect($this->resolver->resolve("{{range_prev_start('7d')}}", []))
        ->toBe(now()->utc()->subDays(14)->toDateString());
    expect($this->resolver->resolve("{{range_prev_start('90d')}}", []))
        ->toBe(now()->utc()->subDays(180)->toDateString());
    expect($this->resolver->resolve("{{range_prev_start('1y')}}", []))
        ->toBe(now()->utc()->subYears(2)->toDateString());
    expect($this->resolver->resolve("{{range_prev_start('today')}}", []))
        ->toBe(now()->utc()->subDay()->toDateString());

    // "Todo" (empty preset) resolves empty — the condition skips server-side,
    // mirroring range_start.
    expect($this->resolver->resolve("{{range_prev_start('')}}", []))->toBe('');
    expect($this->resolver->resolve("{{range_prev_start(default(params.range, '30d'))}}", ['params' => []]))
        ->toBe(now()->utc()->subDays(60)->toDateString());
});
