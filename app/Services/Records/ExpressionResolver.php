<?php

namespace App\Services\Records;

use Illuminate\Support\Facades\Log;

/**
 * Resolves manifest value_expression strings against the runtime context.
 *
 * Recognized syntax:
 *   {{current_user.id}}             current user id
 *   {{current_user.<key>}}          any current_user scalar key
 *   {{params.<X>}}                  page param X
 *   {{form.<X>}}                    submitted form field X
 *   {{trigger.<path>}}              workflow trigger payload (dot path)
 *   {{vars.<X>}}                    workflow-scoped variable
 *   {{steps.<id>.output.<path>}}    output of a previous workflow step
 *   {{row.<path>}}                  per-row context (table action columns)
 *   {{record.id}} / {{record.data.<slug>}}  the record just created/updated
 *                                   earlier in the same action sequence
 *
 * Functions (callable at the head of an expression, with optional
 * trailing dot access):
 *   {{now()}}, {{now('Y-m-d')}}     current datetime (UTC), optional format
 *   {{today()}}                     current date (UTC)
 *   {{random(arr)}}                 pick a random element
 *   {{count(arr)}}                  array length (or string length)
 *   {{length(s)}}                   string length (alias of count for strings)
 *   {{first(arr)}}                  arr[0] or null
 *   {{last(arr)}}                   end(arr) or null
 *   {{slice(arr, n)}}               first n elements
 *   {{pluck(arr, 'field')}}         array of values at the dotted field
 *   {{sum(arr, 'field')}}           sum of values (optionally pluck first)
 *   {{min(arr, 'field')}}, {{max(arr, 'field')}}, {{avg(arr, 'field')}}
 *   {{upper(s)}}, {{lower(s)}}      case transforms
 *   {{concat(a, b, …)}}             string concatenation
 *   {{default(value, fallback)}}    fallback when value is null
 *
 * Trailing dot access works on the return value, so e.g.:
 *   {{random(vars.peliculas.rows).data.titulo}}
 *
 * Unknown tokens return null — never throw. The manifest validator catches
 * malformed expressions at write-time; at run-time we degrade gracefully.
 */
class ExpressionResolver
{
    /**
     * Roots that map onto top-level context keys. Listed once so we don't
     * duplicate the name list inline.
     */
    private const CONTEXT_ROOTS = ['current_user', 'params', 'form', 'trigger', 'vars', 'row', 'record'];

    public function __construct(private SafeExpressionEvaluator $safe) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function resolve(string $expression, array $context): mixed
    {
        $trimmed = trim($expression);

        // Whole string is exactly one token → return its TYPED value (a number
        // stays a number, an array stays an array). Callers writing into a field,
        // a record_id_expression or a condition rely on the un-stringified value.
        if (preg_match('/^\{\{\s*(.+?)\s*\}\}$/', $trimmed, $matches)) {
            return $this->resolveToken(trim($matches[1]), $context);
        }

        // No token at all → literal passthrough.
        if (! str_contains($expression, '{{')) {
            return $expression;
        }

        // Mixed template: literal text interleaved with one or more {{…}} tokens
        // (e.g. a log message, an agent.invoke prompt, an http url). Interpolate
        // each token in place as a string — without this an embedded token would
        // reach its consumer un-resolved, as the raw "{{…}}".
        return preg_replace_callback(
            '/\{\{\s*(.+?)\s*\}\}/',
            function (array $m) use ($context): string {
                $value = $this->resolveToken(trim($m[1]), $context);

                return is_scalar($value) ? (string) $value : '';
            },
            $expression,
        );
    }

    /**
     * Resolve a single token's inner expression to its typed value.
     *
     * @param  array<string, mixed>  $context
     */
    private function resolveToken(string $inner, array $context): mixed
    {
        // Prefer the real expression engine — it adds arithmetic, comparison,
        // boolean logic and ternaries on top of the legacy path-and-function
        // grammar. Fall back to the legacy walker for the few constructs the
        // grammar can't parse, notably numeric dotted indices like `rows.0`.
        try {
            return $this->safe->evaluate($inner, $context);
        } catch (\Throwable $e) {
            $fallback = $this->evaluate($inner, $context);

            // A legitimate fallback (e.g. numeric dotted index) resolves to a
            // value. When the legacy walker ALSO yields null the expression
            // genuinely failed — an unknown function, bad arity or unsupported
            // syntax — so surface it instead of silently returning null (which
            // otherwise shows up far downstream as a validation error).
            if ($fallback === null) {
                Log::warning('Expression failed to evaluate; resolved to null', [
                    'expression' => $inner,
                    'error' => $e->getMessage(),
                    'context_keys' => array_keys($context),
                ]);
            }

            return $fallback;
        }
    }

    /**
     * Resolve a single inner-expression term. A term is one of:
     *   - a string/number literal (`'foo'`, `42`, `3.14`)
     *   - a function call: `name(args…)` optionally followed by `.dot.path`
     *   - a dot-path: `vars.peliculas.rows.0.data.titulo`
     *
     * @param  array<string, mixed>  $context
     */
    private function evaluate(string $expr, array $context): mixed
    {
        $expr = trim($expr);
        if ($expr === '') {
            return null;
        }

        // String literal: 'foo' or "foo".
        if ((str_starts_with($expr, "'") && str_ends_with($expr, "'"))
            || (str_starts_with($expr, '"') && str_ends_with($expr, '"'))) {
            return substr($expr, 1, -1);
        }

        // Numeric literal — supports negatives and decimals.
        if (preg_match('/^-?\d+(\.\d+)?$/', $expr) === 1) {
            return str_contains($expr, '.') ? (float) $expr : (int) $expr;
        }

        // Function call: name(...args)[.trailing.path]
        if (preg_match('/^([a-z_][a-z0-9_]*)\s*\(/i', $expr, $m) === 1) {
            $name = $m[1];
            $openIdx = strpos($expr, '(');
            $closeIdx = $this->findMatchingClose($expr, $openIdx);
            if ($closeIdx === null) {
                return null; // unbalanced parens — bail rather than throw
            }
            $argsStr = substr($expr, $openIdx + 1, $closeIdx - $openIdx - 1);
            $args = $this->parseArgs($argsStr, $context);
            $value = $this->callFunction($name, $args, $context);

            // Optional `.foo.bar` access on the function's return.
            $trail = substr($expr, $closeIdx + 1);
            if ($trail !== '' && str_starts_with($trail, '.')) {
                $value = $this->dig($value, substr($trail, 1));
            }

            return $value;
        }

        // Otherwise: a dot-path against the context roots.
        return $this->resolvePath($expr, $context);
    }

    /**
     * Walk a dotted path against the named context roots — same semantics
     * as before functions existed.
     *
     * @param  array<string, mixed>  $context
     */
    private function resolvePath(string $path, array $context): mixed
    {
        foreach (self::CONTEXT_ROOTS as $root) {
            $prefix = $root.'.';
            if (str_starts_with($path, $prefix)) {
                return $this->dig($context[$root] ?? null, substr($path, strlen($prefix)));
            }
        }

        // steps.<id>.output.<path>
        if (str_starts_with($path, 'steps.')) {
            $rest = substr($path, strlen('steps.'));
            $parts = explode('.', $rest, 2);
            $stepId = $parts[0];
            $tail = $parts[1] ?? '';

            return $this->dig($context['steps'][$stepId] ?? null, $tail);
        }

        return null;
    }

    /**
     * Split a function call's args by top-level commas, respecting nested
     * parens and quoted strings. Each chunk is recursively evaluated so
     * paths, literals and nested function calls all work as args.
     *
     * @param  array<string, mixed>  $context
     * @return list<mixed>
     */
    private function parseArgs(string $argsStr, array $context): array
    {
        $argsStr = trim($argsStr);
        if ($argsStr === '') {
            return [];
        }

        $out = [];
        $buf = '';
        $depth = 0;
        $inString = false;
        $strChar = null;
        $len = strlen($argsStr);
        for ($i = 0; $i < $len; $i++) {
            $c = $argsStr[$i];
            if ($inString) {
                $buf .= $c;
                if ($c === $strChar && ($i === 0 || $argsStr[$i - 1] !== '\\')) {
                    $inString = false;
                    $strChar = null;
                }

                continue;
            }
            if ($c === '"' || $c === "'") {
                $inString = true;
                $strChar = $c;
                $buf .= $c;

                continue;
            }
            if ($c === '(') {
                $depth++;
                $buf .= $c;

                continue;
            }
            if ($c === ')') {
                $depth--;
                $buf .= $c;

                continue;
            }
            if ($c === ',' && $depth === 0) {
                $out[] = $this->evaluate(trim($buf), $context);
                $buf = '';

                continue;
            }
            $buf .= $c;
        }
        if (trim($buf) !== '') {
            $out[] = $this->evaluate(trim($buf), $context);
        }

        return $out;
    }

    /** Returns the offset of the matching `)` for the `(` at $openIdx, or null. */
    private function findMatchingClose(string $expr, int $openIdx): ?int
    {
        $depth = 0;
        $inString = false;
        $strChar = null;
        $len = strlen($expr);
        for ($i = $openIdx; $i < $len; $i++) {
            $c = $expr[$i];
            if ($inString) {
                if ($c === $strChar && ($i === 0 || $expr[$i - 1] !== '\\')) {
                    $inString = false;
                    $strChar = null;
                }

                continue;
            }
            if ($c === '"' || $c === "'") {
                $inString = true;
                $strChar = $c;

                continue;
            }
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<mixed>  $args
     * @param  array<string, mixed>  $context
     */
    private function callFunction(string $name, array $args, array $context): mixed
    {
        return match ($name) {
            'now' => $this->fnNow($args),
            'today' => now()->utc()->toDateString(),
            'random' => $this->fnRandom($args[0] ?? null),
            'count', 'length' => $this->fnCount($args[0] ?? null),
            'first' => $this->fnNth($args[0] ?? null, 0),
            'last' => $this->fnLast($args[0] ?? null),
            'slice' => $this->fnSlice($args[0] ?? null, $args[1] ?? null),
            'pluck' => $this->fnPluck($args[0] ?? null, $args[1] ?? null),
            'sum' => $this->fnAggregate($args[0] ?? null, $args[1] ?? null, 'sum'),
            'min' => $this->fnAggregate($args[0] ?? null, $args[1] ?? null, 'min'),
            'max' => $this->fnAggregate($args[0] ?? null, $args[1] ?? null, 'max'),
            'avg' => $this->fnAggregate($args[0] ?? null, $args[1] ?? null, 'avg'),
            'upper' => is_string($args[0] ?? null) ? mb_strtoupper($args[0]) : null,
            'lower' => is_string($args[0] ?? null) ? mb_strtolower($args[0]) : null,
            'concat' => $this->fnConcat($args),
            'default' => $args[0] ?? ($args[1] ?? null),
            'days_ago' => now()->utc()->subDays((int) ($args[0] ?? 0))->toDateString(),
            'months_ago' => now()->utc()->subMonthsNoOverflow((int) ($args[0] ?? 0))->toDateString(),
            'start_of_week' => now()->utc()->subWeeks((int) ($args[0] ?? 0))->startOfWeek()->toDateString(),
            'start_of_month' => now()->utc()->subMonthsNoOverflow((int) ($args[0] ?? 0))->startOfMonth()->toDateString(),
            'start_of_year' => now()->utc()->subYears((int) ($args[0] ?? 0))->startOfYear()->toDateString(),
            'range_start' => match (is_string($args[0] ?? null) ? $args[0] : '') {
                'today' => now()->utc()->toDateString(),
                '7d' => now()->utc()->subDays(7)->toDateString(),
                '30d' => now()->utc()->subDays(30)->toDateString(),
                '90d' => now()->utc()->subDays(90)->toDateString(),
                '1y' => now()->utc()->subYear()->toDateString(),
                default => '',
            },
            // Start of the PREVIOUS window of the same preset — with range_start
            // as its exclusive end it brackets the period-over-period compare
            // window KPI delta chips read. Empty preset ("Todo") resolves empty,
            // same as range_start, so both compare bounds skip server-side.
            'range_prev_start' => match (is_string($args[0] ?? null) ? $args[0] : '') {
                'today' => now()->utc()->subDay()->toDateString(),
                '7d' => now()->utc()->subDays(14)->toDateString(),
                '30d' => now()->utc()->subDays(60)->toDateString(),
                '90d' => now()->utc()->subDays(180)->toDateString(),
                '1y' => now()->utc()->subYears(2)->toDateString(),
                default => '',
            },
            default => null,
        };
    }

    /** @param  list<mixed>  $args */
    private function fnNow(array $args): string
    {
        $format = is_string($args[0] ?? null) ? $args[0] : null;

        return $format ? now()->utc()->format($format) : now()->utc()->toIso8601String();
    }

    private function fnRandom(mixed $arr): mixed
    {
        if (! is_array($arr) || $arr === []) {
            return null;
        }
        $keys = array_keys($arr);

        return $arr[$keys[random_int(0, count($keys) - 1)]];
    }

    private function fnCount(mixed $v): int
    {
        if (is_array($v)) {
            return count($v);
        }
        if (is_string($v)) {
            return mb_strlen($v);
        }

        return 0;
    }

    private function fnNth(mixed $arr, int $index): mixed
    {
        if (! is_array($arr) || $arr === []) {
            return null;
        }
        $vals = array_values($arr);

        return $vals[$index] ?? null;
    }

    private function fnLast(mixed $arr): mixed
    {
        if (! is_array($arr) || $arr === []) {
            return null;
        }
        $vals = array_values($arr);

        return $vals[count($vals) - 1];
    }

    private function fnSlice(mixed $arr, mixed $n): array
    {
        if (! is_array($arr) || ! is_int($n)) {
            return [];
        }

        return array_slice($arr, 0, max(0, $n));
    }

    /**
     * Pluck the values at $field from every element of $arr. $field is
     * dotted so callers can reach into nested structures, e.g.
     * `pluck(vars.peliculas.rows, 'data.titulo')`.
     */
    private function fnPluck(mixed $arr, mixed $field): array
    {
        if (! is_array($arr) || ! is_string($field)) {
            return [];
        }

        $out = [];
        foreach ($arr as $item) {
            $out[] = $this->dig($item, $field);
        }

        return $out;
    }

    /**
     * Numeric aggregation (sum/min/max/avg). When $field is given, pluck
     * first; otherwise treat $arr as a flat list of numbers.
     */
    private function fnAggregate(mixed $arr, mixed $field, string $op): float|int|null
    {
        if (! is_array($arr) || $arr === []) {
            return null;
        }
        $values = is_string($field) ? $this->fnPluck($arr, $field) : array_values($arr);
        $numeric = array_filter($values, fn ($v) => is_int($v) || is_float($v));
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

    /** @param  list<mixed>  $args */
    private function fnConcat(array $args): string
    {
        return implode('', array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $args));
    }

    /**
     * Walk a dot-path through a nested array/object. Returns null on any miss.
     */
    private function dig(mixed $value, string $path): mixed
    {
        if ($path === '') {
            return $value;
        }
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
}
