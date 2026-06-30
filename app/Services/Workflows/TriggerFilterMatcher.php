<?php

namespace App\Services\Workflows;

use App\Services\Records\RecordQueryService;

/**
 * Evaluates a manifest `filter_expression` against a single record IN MEMORY,
 * so a record.created/updated/deleted trigger fires only when its filter
 * matches. Mirrors the scalar comparison semantics of the SQL filter path
 * (RecordQueryService) so a trigger filter means the same thing it would in a
 * record.query step — empty comparison value is a no-op, text ops are
 * case-insensitive, and a NULL/absent field never matches a value comparison.
 *
 * Scope: leaf conditions (eq/neq/gt/gte/lt/lte/in/not_in/contains/starts_with/
 * ends_with/is_null/is_not_null/between) over scalar fields, plus and/or/not
 * groups. `related` traversal and `value_expression` need a DB query / the
 * workflow context, so they are rejected for trigger filters at manifest
 * validation and never reach this matcher.
 */
class TriggerFilterMatcher
{
    private const NUMERIC_TYPES = ['number', 'currency', 'rating', 'slider'];

    /**
     * @param  array<string, mixed>  $filter  a filter_expression
     * @param  array<string, mixed>  $object  the manifest object definition
     * @param  array<string, mixed>  $record  record payload ({id, data, created_at, updated_at})
     */
    public function matches(array $filter, array $object, array $record): bool
    {
        return match ($filter['op'] ?? null) {
            'and' => $this->all($filter['conditions'] ?? [], $object, $record),
            'or' => $this->any($filter['conditions'] ?? [], $object, $record),
            'not' => ! $this->matches($filter['condition'] ?? [], $object, $record),
            default => $this->matchLeaf($filter['op'] ?? null, $filter, $object, $record),
        };
    }

    /**
     * @param  array<int, mixed>  $conditions
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $record
     */
    private function all(array $conditions, array $object, array $record): bool
    {
        foreach ($conditions as $condition) {
            if (is_array($condition) && ! $this->matches($condition, $object, $record)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, mixed>  $conditions
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $record
     */
    private function any(array $conditions, array $object, array $record): bool
    {
        foreach ($conditions as $condition) {
            if (is_array($condition) && $this->matches($condition, $object, $record)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $expr
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $record
     */
    private function matchLeaf(?string $op, array $expr, array $object, array $record): bool
    {
        $field = $this->resolveField($object, (string) ($expr['field_id'] ?? ''));
        if ($field === null) {
            return false; // unknown field — manifest validation should prevent this
        }
        $actual = $this->fieldValue($field, $record);

        if ($op === 'is_null') {
            return $this->isEmpty($actual);
        }
        if ($op === 'is_not_null') {
            return ! $this->isEmpty($actual);
        }

        $value = $expr['value'] ?? null;

        // Mirror RecordQueryService: an empty comparison value doesn't constrain.
        if ($this->isEmpty($value)) {
            return true;
        }
        // A null/absent field never matches a value comparison (SQL: NULL <op> x → NULL).
        if ($this->isEmpty($actual)) {
            return false;
        }

        $type = (string) ($field['type'] ?? 'string');

        return match ($op) {
            'eq' => $this->equals($actual, $value, $type),
            'neq' => ! $this->equals($actual, $value, $type),
            'gt' => $this->compare($actual, $value, $type) > 0,
            'gte' => $this->compare($actual, $value, $type) >= 0,
            'lt' => $this->compare($actual, $value, $type) < 0,
            'lte' => $this->compare($actual, $value, $type) <= 0,
            'in' => $this->inSet($actual, $this->toList($value)),
            'not_in' => ! $this->inSet($actual, $this->toList($value)),
            'contains' => $this->stringOp($actual, $value, 'contains'),
            'starts_with' => $this->stringOp($actual, $value, 'starts'),
            'ends_with' => $this->stringOp($actual, $value, 'ends'),
            'between' => $this->between($actual, $value, $type),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>|null
     */
    private function resolveField(array $object, string $fieldId): ?array
    {
        foreach ($object['fields'] ?? [] as $field) {
            if (($field['id'] ?? null) === $fieldId) {
                return $field;
            }
        }

        return RecordQueryService::systemField($fieldId);
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $record
     */
    private function fieldValue(array $field, array $record): mixed
    {
        if (isset($field['_system_column'])) {
            return $record[$field['_system_column']] ?? null;
        }

        return $record['data'][$field['slug']] ?? null;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || (is_array($value) && $value === []);
    }

    private function equals(mixed $actual, mixed $value, string $type): bool
    {
        if (is_array($actual)) { // multi_select / relation array
            return in_array((string) $value, array_map('strval', $actual), true);
        }
        if (in_array($type, self::NUMERIC_TYPES, true) && is_numeric($actual) && is_numeric($value)) {
            return (float) $actual === (float) $value;
        }
        if ($type === 'boolean') {
            return (bool) $actual === $this->toBool($value);
        }

        return (string) $actual === (string) $value;
    }

    private function compare(mixed $actual, mixed $value, string $type): int
    {
        if (in_array($type, self::NUMERIC_TYPES, true) && is_numeric($actual) && is_numeric($value)) {
            return (float) $actual <=> (float) $value;
        }

        // date / datetime are stored as ISO strings, which sort lexically.
        return strcmp((string) $actual, (string) $value);
    }

    /**
     * @param  list<string>  $set
     */
    private function inSet(mixed $actual, array $set): bool
    {
        if (is_array($actual)) {
            foreach ($actual as $item) {
                if (in_array((string) $item, $set, true)) {
                    return true;
                }
            }

            return false;
        }

        return in_array((string) $actual, $set, true);
    }

    private function stringOp(mixed $actual, mixed $needle, string $mode): bool
    {
        $needle = mb_strtolower((string) $needle);
        $candidates = is_array($actual) ? $actual : [$actual];

        foreach ($candidates as $candidate) {
            $haystack = mb_strtolower((string) $candidate);
            $hit = match ($mode) {
                'contains' => str_contains($haystack, $needle),
                'starts' => str_starts_with($haystack, $needle),
                'ends' => str_ends_with($haystack, $needle),
                default => false,
            };
            if ($hit) {
                return true;
            }
        }

        return false;
    }

    private function between(mixed $actual, mixed $value, string $type): bool
    {
        $bounds = $this->toList($value);
        if (count($bounds) < 2) {
            return false;
        }

        return $this->compare($actual, $bounds[0], $type) >= 0
            && $this->compare($actual, $bounds[1], $type) <= 0;
    }

    /**
     * @return list<string>
     */
    private function toList(mixed $value): array
    {
        return array_map('strval', array_values((array) $value));
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }
}
