<?php

namespace App\Services\Records;

/**
 * Applies a data-source query (filter / sort / limit) to connected-object rows
 * IN MEMORY, after the external read. Connected sources can't run our filter
 * grammar server-side (REST pushes down only mapped equality params; an MCP
 * tool has no generic param surface), so without this a page filter — notably
 * the dashboard date-range presets — was a silent no-op over live external
 * data: the board showed everything no matter the preset.
 *
 * Semantics mirror RecordQueryService where they can:
 *  - leaf ops eq/neq/gt/gte/lt/lte/in/not_in/contains/starts_with/ends_with/
 *    is_null/is_not_null/between, groups and/or/not;
 *  - `value_expression` resolves through ExpressionResolver with the render
 *    context (params, current_user, …);
 *  - a value op whose value resolves empty (unset {{params.x}}) is a no-op, so
 *    the unfiltered set shows — same skip rule as the SQL path ("Todo" works);
 *  - `related` cannot be evaluated over passthrough rows and is skipped
 *    (documented degradation), never an error.
 *
 * Offset is intentionally NOT re-applied: a REST source may have already paged
 * with it, and skipping twice would drop rows.
 */
class InMemoryRowFilter
{
    private const VALUE_OPS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'contains', 'starts_with', 'ends_with', 'between'];

    private const NUMERIC_TYPES = ['number', 'currency', 'rating', 'slider'];

    private const TEMPORAL_TYPES = ['date', 'datetime'];

    public function __construct(private readonly ExpressionResolver $expressions) {}

    /**
     * @param  list<array{id: mixed, data: array<string, mixed>}>  $rows
     * @param  array<string, mixed>  $query  the block's data-source (filter/sort/limit)
     * @param  array<string, mixed>  $object  manifest object node (fields → slug/type)
     * @param  array<string, mixed>  $context  render context for value_expression
     * @return list<array{id: mixed, data: array<string, mixed>}>
     */
    public function apply(array $rows, array $query, array $object, array $context = []): array
    {
        $fields = [];
        foreach ($object['fields'] ?? [] as $f) {
            $fields[$f['id']] = ['slug' => $f['slug'] ?? $f['id'], 'type' => $f['type'] ?? 'string'];
        }
        $fields['sys_created_at'] ??= ['slug' => 'sys_created_at', 'type' => 'datetime'];
        $fields['sys_updated_at'] ??= ['slug' => 'sys_updated_at', 'type' => 'datetime'];

        $filter = $query['filter'] ?? null;
        if (is_array($filter)) {
            $rows = array_values(array_filter(
                $rows,
                fn (array $row): bool => $this->matches($filter, $row['data'] ?? [], $fields, $context),
            ));
        }

        $sort = $query['sort'][0] ?? null;
        if (is_array($sort) && isset($fields[$sort['field_id'] ?? ''])) {
            $field = $fields[$sort['field_id']];
            $slug = $field['slug'];
            $desc = ($sort['direction'] ?? 'asc') === 'desc';
            $temporal = in_array($field['type'], self::TEMPORAL_TYPES, true);
            usort($rows, function (array $a, array $b) use ($slug, $desc, $temporal): int {
                $av = $a['data'][$slug] ?? null;
                $bv = $b['data'][$slug] ?? null;
                $cmp = $temporal
                    ? (self::timestamp($av) <=> self::timestamp($bv))
                    : ($av <=> $bv);

                return $desc ? -$cmp : $cmp;
            });
        }

        if (isset($query['limit']) && is_numeric($query['limit'])) {
            $rows = array_slice($rows, 0, max(0, (int) $query['limit']));
        }

        return array_values($rows);
    }

    /**
     * @param  array<string, mixed>  $expr
     * @param  array<string, mixed>  $data
     * @param  array<string, array{slug: string, type: string}>  $fields
     * @param  array<string, mixed>  $context
     */
    private function matches(array $expr, array $data, array $fields, array $context): bool
    {
        $op = $expr['op'] ?? null;

        if ($op === 'and') {
            foreach ($expr['conditions'] ?? [] as $cond) {
                if (is_array($cond) && ! $this->matches($cond, $data, $fields, $context)) {
                    return false;
                }
            }

            return true;
        }
        if ($op === 'or') {
            $conditions = array_filter($expr['conditions'] ?? [], 'is_array');
            if ($conditions === []) {
                return true;
            }
            foreach ($conditions as $cond) {
                if ($this->matches($cond, $data, $fields, $context)) {
                    return true;
                }
            }

            return false;
        }
        if ($op === 'not') {
            return ! (is_array($expr['condition'] ?? null)
                && $this->matches($expr['condition'], $data, $fields, $context));
        }
        // Passthrough rows can't traverse relations — degrade to a no-op.
        if ($op === 'related') {
            return true;
        }

        $field = $fields[$expr['field_id'] ?? ''] ?? null;
        if ($field === null) {
            // A condition on a field the object doesn't declare is a no-op,
            // matching the partial-tolerance of the connected read path.
            return true;
        }
        $actual = $data[$field['slug']] ?? null;

        if ($op === 'is_null') {
            return $actual === null || $actual === '';
        }
        if ($op === 'is_not_null') {
            return $actual !== null && $actual !== '';
        }

        $value = array_key_exists('value', $expr)
            ? $expr['value']
            : (isset($expr['value_expression']) ? $this->expressions->resolve((string) $expr['value_expression'], $context) : null);

        // Same skip rule as the SQL path: an empty resolved value (an unset or
        // cleared page filter) disables the condition instead of matching ''.
        if (in_array($op, self::VALUE_OPS, true)
            && ($value === null || $value === '' || (is_array($value) && $value === []))) {
            return true;
        }

        $numeric = in_array($field['type'], self::NUMERIC_TYPES, true);
        // A date/datetime field compares as a point in time, not as a string:
        // connected rows arrive in whatever format the external system uses
        // ("2026-07-05T01:00:00Z", "05/07/2026 10:30", epoch seconds…), and a
        // lexicographic compare against range_start()'s "YYYY-MM-DD" is wrong
        // for every non-ISO shape — the date presets then silently no-op.
        $temporal = in_array($field['type'], self::TEMPORAL_TYPES, true);
        $cast = function (mixed $v) use ($numeric, $temporal): mixed {
            if ($numeric && is_numeric($v)) {
                return $v + 0;
            }
            if ($temporal) {
                $ts = self::timestamp($v);
                if ($ts !== null) {
                    return $ts;
                }
            }

            return is_scalar($v) ? (string) $v : $v;
        };

        // When either side of a temporal comparison can't be parsed, fall back
        // to plain casting for BOTH so the two sides stay comparable.
        if ($temporal && in_array($op, ['gt', 'gte', 'lt', 'lte', 'between', 'eq', 'neq'], true)) {
            $values = $op === 'between' ? (is_array($value) ? $value : []) : [$value];
            $allParse = self::timestamp($actual) !== null;
            foreach ($values as $v) {
                $allParse = $allParse && self::timestamp($v) !== null;
            }
            if (! $allParse) {
                $cast = fn (mixed $v): mixed => is_scalar($v) ? (string) $v : $v;
            }
        }

        return match ($op) {
            'eq' => $cast($actual) == $cast($value),
            'neq' => $cast($actual) != $cast($value),
            'gt' => $actual !== null && $cast($actual) > $cast($value),
            'gte' => $actual !== null && $cast($actual) >= $cast($value),
            'lt' => $actual !== null && $cast($actual) < $cast($value),
            'lte' => $actual !== null && $cast($actual) <= $cast($value),
            'in' => is_array($value) && in_array($cast($actual), array_map($cast, $value), false),
            'not_in' => ! (is_array($value) && in_array($cast($actual), array_map($cast, $value), false)),
            'contains' => is_scalar($actual) && is_scalar($value) && mb_stripos((string) $actual, (string) $value) !== false,
            'starts_with' => is_scalar($actual) && is_scalar($value) && mb_stripos((string) $actual, (string) $value) === 0,
            'ends_with' => is_scalar($actual) && is_scalar($value)
                && str_ends_with(mb_strtolower((string) $actual), mb_strtolower((string) $value)),
            'between' => is_array($value) && count($value) === 2 && $actual !== null
                && $cast($actual) >= $cast($value[0]) && $cast($actual) <= $cast($value[1]),
            default => true,
        };
    }

    /**
     * Best-effort parse of a temporal value to a unix timestamp. Accepts
     * anything strtotime understands (ISO 8601, "05/07/2026 10:30", "July 5
     * 2026"…) plus bare epoch numbers in seconds or milliseconds. Null when the
     * value is empty or unparseable — the caller then falls back to string
     * comparison rather than comparing garbage.
     */
    public static function timestamp(mixed $value): ?int
    {
        if ($value === null || $value === '' || is_bool($value) || is_array($value)) {
            return null;
        }

        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            $n = (float) $value;
            if ($n >= 1e12) {
                return (int) ($n / 1000);   // epoch milliseconds
            }
            if ($n >= 1e8) {
                return (int) $n;            // epoch seconds (≥ 1973)
            }

            return null;                    // small number — not a date
        }

        $ts = strtotime((string) $value);

        return $ts === false ? null : $ts;
    }
}
