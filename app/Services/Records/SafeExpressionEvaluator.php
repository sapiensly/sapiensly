<?php

namespace App\Services\Records;

use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/**
 * Evaluates a bare manifest expression (the inner part of a `{{ … }}` token)
 * as a real, sandboxed expression: arithmetic, comparison, boolean logic,
 * ternaries, member access and a curated catalog of functions.
 *
 * Backed by symfony/expression-language, which is a sandbox by design — it can
 * ONLY call functions that are explicitly registered here, never arbitrary PHP.
 * This is what makes `{{ monto * 1.16 }}`, `{{ a > b && c }}` and
 * `{{ estado == "activo" ? 1 : 0 }}` work, none of which the legacy
 * regex-substitution resolver could evaluate.
 *
 * Context shape mirrors ExpressionResolver: the top-level keys
 * (current_user, params, form, trigger, vars, steps, row) are bound as
 * variables. Associative arrays are cast to objects so the legacy dotted
 * syntax (`vars.x`, `trigger.record.data.slug`) keeps working; list arrays are
 * left as arrays so `rows[0]`, count() and the aggregation functions operate on
 * them. Numeric dotted indices (`rows.0`) are NOT supported by the grammar —
 * callers fall back to ExpressionResolver's legacy walker for those.
 *
 * evaluate() throws on parse/eval failure (unknown grammar, deep miss on a
 * non-object) so callers can fall back; it never emits PHP warnings for missing
 * properties.
 */
class SafeExpressionEvaluator
{
    /**
     * The authoritative catalog of callable function names. This is the single
     * source of truth: registerFunctions() must register exactly these (guarded
     * by a parity test), the manifest validator rejects calls to anything not
     * in this list at save time, and the builder AI is told to use only these.
     *
     * @var list<string>
     */
    public const FUNCTIONS = [
        'now', 'today', 'random', 'count', 'length', 'first', 'last', 'slice',
        'pluck', 'sum', 'min', 'max', 'avg', 'upper', 'lower', 'concat',
        'default', 'round', 'abs', 'floor', 'ceil',
        'days_ago', 'months_ago', 'start_of_week', 'start_of_month', 'start_of_year',
    ];

    private ExpressionLanguage $engine;

    public function __construct()
    {
        $this->engine = new ExpressionLanguage;
        $this->registerFunctions();
    }

    /**
     * @return list<string>
     */
    public static function functionNames(): array
    {
        return self::FUNCTIONS;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function evaluate(string $expression, array $context): mixed
    {
        $variables = [];
        foreach ($context as $key => $value) {
            // Context roots (params, form, row, …) are maps: an EMPTY one must
            // still be an object so `params.x` reads as null instead of throwing
            // (an empty array would objectify to a list, and member access on it
            // fails — breaking e.g. `{{not params.flag}}` when no params are set).
            $variables[$key] = $value === [] ? new \stdClass : $this->objectify($value);
        }

        // EL emits an E_WARNING when a property is missing before it throws on
        // the next access; mute warnings for the evaluation window only so a
        // missing reference degrades quietly instead of polluting the log.
        set_error_handler(static fn (): bool => true, E_WARNING);

        try {
            return $this->deobjectify($this->engine->evaluate($expression, $variables));
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Undo objectify() on the result so callers see the same array shape the
     * legacy resolver returned (e.g. `{{trigger.record.data}}` is an array, not
     * a stdClass) — important because resolved values are written straight into
     * record JSON columns.
     */
    private function deobjectify(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }
        if (is_array($value)) {
            return array_map(fn ($item) => $this->deobjectify($item), $value);
        }

        return $value;
    }

    /**
     * Recursively turn associative arrays into stdClass (so dotted access
     * works) while leaving list arrays as arrays (so indexing/iteration work).
     */
    private function objectify(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->objectify($item), $value);
        }

        $object = new \stdClass;
        foreach ($value as $key => $item) {
            $object->{$key} = $this->objectify($item);
        }

        return $object;
    }

    private function registerFunctions(): void
    {
        foreach ($this->functionMap() as $name => $evaluator) {
            $this->engine->addFunction(new ExpressionFunction(
                $name,
                // Compiler — unused (we only ever evaluate()), kept valid.
                static fn (): string => 'null',
                static fn (array $values, ...$args): mixed => $evaluator(...$args),
            ));
        }
    }

    /**
     * The curated, side-effect-free function catalog. Ported from the legacy
     * ExpressionResolver so existing expressions keep their semantics, plus a
     * few pure-math helpers that arithmetic formulas commonly need.
     *
     * @return array<string, callable>
     */
    private function functionMap(): array
    {
        return [
            'now' => fn (?string $format = null): string => $format
                ? now()->utc()->format($format)
                : now()->utc()->toIso8601String(),
            'today' => fn (): string => now()->utc()->toDateString(),
            'random' => fn (mixed ...$args): mixed => $this->fnRandom($args),
            'count' => fn (mixed $v): int => $this->fnCount($v),
            'length' => fn (mixed $v): int => $this->fnCount($v),
            'first' => fn (mixed $arr): mixed => $this->fnNth($arr, 0),
            'last' => fn (mixed $arr): mixed => $this->fnLast($arr),
            'slice' => fn (mixed $arr, mixed $n): array => $this->fnSlice($arr, $n),
            'pluck' => fn (mixed $arr, mixed $field): array => $this->fnPluck($arr, $field),
            'sum' => fn (mixed $arr, mixed $field = null): float|int|null => $this->fnAggregate($arr, $field, 'sum'),
            'min' => fn (mixed $arr, mixed $field = null): float|int|null => $this->fnAggregate($arr, $field, 'min'),
            'max' => fn (mixed $arr, mixed $field = null): float|int|null => $this->fnAggregate($arr, $field, 'max'),
            'avg' => fn (mixed $arr, mixed $field = null): float|int|null => $this->fnAggregate($arr, $field, 'avg'),
            'upper' => fn (mixed $s): ?string => is_string($s) ? mb_strtoupper($s) : null,
            'lower' => fn (mixed $s): ?string => is_string($s) ? mb_strtolower($s) : null,
            'concat' => fn (mixed ...$args): string => $this->fnConcat($args),
            'default' => fn (mixed $value, mixed $fallback): mixed => $value ?? $fallback,
            'round' => fn (mixed $n, mixed $precision = 0): float => round((float) $n, (int) $precision),
            'abs' => fn (mixed $n): float|int => abs(is_int($n) ? $n : (float) $n),
            'floor' => fn (mixed $n): float => floor((float) $n),
            'ceil' => fn (mixed $n): float => ceil((float) $n),
            // Date helpers (UTC, return YYYY-MM-DD) for period filters — e.g. a
            // previous-period `compare` query without hand-computing dates.
            'days_ago' => fn (mixed $n = 0): string => now()->utc()->subDays((int) $n)->toDateString(),
            'months_ago' => fn (mixed $n = 0): string => now()->utc()->subMonthsNoOverflow((int) $n)->toDateString(),
            'start_of_week' => fn (mixed $offset = 0): string => now()->utc()->subWeeks((int) $offset)->startOfWeek()->toDateString(),
            'start_of_month' => fn (mixed $offset = 0): string => now()->utc()->subMonthsNoOverflow((int) $offset)->startOfMonth()->toDateString(),
            'start_of_year' => fn (mixed $offset = 0): string => now()->utc()->subYears((int) $offset)->startOfYear()->toDateString(),
        ];
    }

    /**
     * Overloaded by argument shape:
     *   random()           → float in [0, 1)   (JS Math.random style)
     *   random(min, max)   → integer in [min, max]
     *   random(array)      → a random element of the array (null if empty)
     *
     * @param  list<mixed>  $args
     */
    private function fnRandom(array $args): mixed
    {
        if ($args === []) {
            return random_int(0, PHP_INT_MAX - 1) / PHP_INT_MAX;
        }

        if (count($args) >= 2 && is_numeric($args[0]) && is_numeric($args[1])) {
            $min = (int) $args[0];
            $max = (int) $args[1];
            if ($min > $max) {
                [$min, $max] = [$max, $min];
            }

            return random_int($min, $max);
        }

        $values = array_values($this->toArray($args[0] ?? null));
        if ($values === []) {
            return null;
        }

        return $values[random_int(0, count($values) - 1)];
    }

    private function fnCount(mixed $v): int
    {
        if (is_string($v)) {
            return mb_strlen($v);
        }

        return count($this->toArray($v));
    }

    private function fnNth(mixed $arr, int $index): mixed
    {
        $values = array_values($this->toArray($arr));

        return $values[$index] ?? null;
    }

    private function fnLast(mixed $arr): mixed
    {
        $values = array_values($this->toArray($arr));

        return $values === [] ? null : $values[count($values) - 1];
    }

    /**
     * @return list<mixed>
     */
    private function fnSlice(mixed $arr, mixed $n): array
    {
        if (! is_int($n)) {
            return [];
        }

        return array_slice($this->toArray($arr), 0, max(0, $n));
    }

    /**
     * @return list<mixed>
     */
    private function fnPluck(mixed $arr, mixed $field): array
    {
        if (! is_string($field)) {
            return [];
        }

        $out = [];
        foreach ($this->toArray($arr) as $item) {
            $out[] = $this->dig($item, $field);
        }

        return $out;
    }

    private function fnAggregate(mixed $arr, mixed $field, string $op): float|int|null
    {
        $items = $this->toArray($arr);
        if ($items === []) {
            return null;
        }
        $values = is_string($field) ? $this->fnPluck($items, $field) : array_values($items);
        $numeric = array_filter($values, fn ($v): bool => is_int($v) || is_float($v));
        if ($numeric === []) {
            return null;
        }

        return match ($op) {
            'sum' => array_sum($numeric),
            'min' => min($numeric),
            'max' => max($numeric),
            'avg' => array_sum($numeric) / count($numeric) * 1.0,
            default => null,
        };
    }

    /**
     * @param  list<mixed>  $args
     */
    private function fnConcat(array $args): string
    {
        return implode('', array_map(fn ($v): string => is_scalar($v) ? (string) $v : '', $args));
    }

    /**
     * Walk a dot-path through a nested array/object. Returns null on any miss.
     */
    private function dig(mixed $value, string $path): mixed
    {
        foreach (explode('.', $path) as $key) {
            if (is_array($value) && array_key_exists($key, $value)) {
                $value = $value[$key];
            } elseif (is_object($value) && isset($value->{$key})) {
                $value = $value->{$key};
            } else {
                return null;
            }
        }

        return $value;
    }

    /**
     * Normalise a value the engine handed us (array, stdClass from objectify,
     * or scalar) into a plain array for the aggregation helpers.
     *
     * @return array<array-key, mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value instanceof \stdClass) {
            return (array) $value;
        }

        return [];
    }
}
