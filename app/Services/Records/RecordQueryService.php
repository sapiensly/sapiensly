<?php

namespace App\Services\Records;

use App\Models\App;
use App\Models\Record;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use RuntimeException;

/**
 * Translates a manifest `query` block into a parameterized SQL query against
 * the records table. Object definitions live in the JSON manifest, so we look
 * up field metadata (slug, type) from the manifest and cast JSONB columns
 * accordingly.
 *
 * Supported operators (filter_expression):
 *   and, or, not (logical)
 *   eq, neq, gt, gte, lt, lte, in, not_in, contains, starts_with, ends_with,
 *   is_null, is_not_null, between
 *
 * Supported field types for filtering: string, long_text, number, currency,
 * boolean, date, datetime, single_select. multi_select and relation are
 * read-only from the JSONB blob for MVP — filtering on them is best-effort.
 */
class RecordQueryService
{
    public function __construct(
        private ExpressionResolver $expressions,
        private DerivedFieldsResolver $derived,
    ) {}

    /**
     * @param  array<string, mixed>  $query  Query block from the manifest
     * @param  array<string, mixed>  $manifest  Active manifest (for field lookup)
     * @param  array<string, mixed>  $context  current_user, params
     * @return Collection<int, Record>
     */
    public function query(App $app, array $query, array $manifest, array $context = []): Collection
    {
        $objectId = $query['object_id'] ?? null;
        if ($objectId === null) {
            throw new InvalidArgumentException('query.object_id is required.');
        }

        $object = $this->findObject($manifest, $objectId);

        $builder = $this->scopeForObject($app, $objectId);

        if (isset($query['filter'])) {
            $this->applyFilter($builder, $query['filter'], $object, $context);
        }

        foreach ($query['sort'] ?? [] as $sort) {
            $field = $this->findField($object, $sort['field_id']);
            $builder->orderByRaw(
                $this->jsonExtract('data', $field).' '.($sort['direction'] === 'desc' ? 'desc' : 'asc'),
            );
        }

        if (isset($query['offset'])) {
            $builder->offset((int) $query['offset']);
        }

        $builder->limit((int) ($query['limit'] ?? 50));

        $records = $builder->get();

        $this->derived->enrich($app, $object, $records, $manifest);

        return $records;
    }

    /**
     * @param  array<string, mixed>  $query  Query block from the manifest
     * @param  array<string, mixed>  $manifest  Active manifest
     * @param  array<string, mixed>  $context
     */
    public function aggregate(
        App $app,
        array $query,
        string $aggregation,
        ?string $fieldId,
        array $manifest,
        array $context = [],
    ): int|float {
        $objectId = $query['object_id'] ?? null;
        if ($objectId === null) {
            throw new InvalidArgumentException('query.object_id is required.');
        }

        $object = $this->findObject($manifest, $objectId);
        $builder = $this->scopeForObject($app, $objectId);

        if (isset($query['filter'])) {
            $this->applyFilter($builder, $query['filter'], $object, $context);
        }

        if ($aggregation === 'count') {
            return $builder->count();
        }

        if ($fieldId === null) {
            throw new InvalidArgumentException("Aggregation '{$aggregation}' requires field_id.");
        }

        $field = $this->findField($object, $fieldId);
        if (! in_array($field['type'], ['number', 'currency', 'rating', 'slider'], true)) {
            throw new InvalidArgumentException(
                "Aggregation '{$aggregation}' requires a numeric field, got '{$field['type']}'.",
            );
        }

        $expr = $this->jsonExtract('data', $field);
        $sqlAgg = match ($aggregation) {
            'sum' => "sum({$expr})",
            'avg' => "avg({$expr})",
            'min' => "min({$expr})",
            'max' => "max({$expr})",
            default => throw new InvalidArgumentException("Unknown aggregation '{$aggregation}'."),
        };

        $result = $builder->selectRaw("{$sqlAgg} as agg")->value('agg');

        return $result === null ? 0 : (float) $result;
    }

    private function scopeForObject(App $app, string $objectId): Builder
    {
        return Record::query()
            ->where('app_id', $app->id)
            ->where('object_definition_id', $objectId);
    }

    /**
     * @param  array<string, mixed>  $expr
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $context
     */
    private function applyFilter(Builder $builder, array $expr, array $object, array $context): void
    {
        $op = $expr['op'] ?? null;

        if ($op === 'and') {
            $builder->where(function (Builder $q) use ($expr, $object, $context) {
                foreach ($expr['conditions'] as $cond) {
                    $this->applyFilter($q, $cond, $object, $context);
                }
            });

            return;
        }

        if ($op === 'or') {
            $builder->where(function (Builder $q) use ($expr, $object, $context) {
                foreach ($expr['conditions'] as $cond) {
                    $q->orWhere(function (Builder $inner) use ($cond, $object, $context) {
                        $this->applyFilter($inner, $cond, $object, $context);
                    });
                }
            });

            return;
        }

        if ($op === 'not') {
            $builder->whereNot(function (Builder $q) use ($expr, $object, $context) {
                $this->applyFilter($q, $expr['condition'], $object, $context);
            });

            return;
        }

        $field = $this->findField($object, $expr['field_id']);
        $value = $this->resolveValue($expr, $context);
        $columnSql = $this->jsonExtract('data', $field);

        match ($op) {
            'eq' => $builder->whereRaw("{$columnSql} = ?", [$this->castParam($value, $field)]),
            'neq' => $builder->whereRaw("{$columnSql} <> ?", [$this->castParam($value, $field)]),
            'gt' => $builder->whereRaw("{$columnSql} > ?", [$this->castParam($value, $field)]),
            'gte' => $builder->whereRaw("{$columnSql} >= ?", [$this->castParam($value, $field)]),
            'lt' => $builder->whereRaw("{$columnSql} < ?", [$this->castParam($value, $field)]),
            'lte' => $builder->whereRaw("{$columnSql} <= ?", [$this->castParam($value, $field)]),
            'in' => $this->applyIn($builder, $columnSql, $field, $value, negate: false),
            'not_in' => $this->applyIn($builder, $columnSql, $field, $value, negate: true),
            'contains' => $builder->whereRaw("({$columnSql})::text ilike ?", ['%'.$value.'%']),
            'starts_with' => $builder->whereRaw("({$columnSql})::text ilike ?", [$value.'%']),
            'ends_with' => $builder->whereRaw("({$columnSql})::text ilike ?", ['%'.$value]),
            'is_null' => isset($field['_system_column'])
                ? $builder->whereRaw("{$field['_system_column']} is null")
                : $builder->whereRaw("data->'{$field['slug']}' is null or data->>? is null", [$field['slug']]),
            'is_not_null' => isset($field['_system_column'])
                ? $builder->whereRaw("{$field['_system_column']} is not null")
                : $builder->whereRaw('data->>? is not null', [$field['slug']]),
            'between' => $this->applyBetween($builder, $columnSql, $field, $value),
            default => throw new InvalidArgumentException("Unknown filter op '{$op}'."),
        };
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function applyIn(Builder $builder, string $columnSql, array $field, mixed $value, bool $negate): void
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException("Operator 'in' requires an array value.");
        }
        if ($value === []) {
            // empty in() — match nothing (or everything for not_in).
            $builder->whereRaw($negate ? '1=1' : '1=0');

            return;
        }
        $placeholders = implode(',', array_fill(0, count($value), '?'));
        $params = array_map(fn ($v) => $this->castParam($v, $field), $value);
        $sql = $negate ? "{$columnSql} not in ({$placeholders})" : "{$columnSql} in ({$placeholders})";
        $builder->whereRaw($sql, $params);
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function applyBetween(Builder $builder, string $columnSql, array $field, mixed $value): void
    {
        if (! is_array($value) || count($value) !== 2) {
            throw new InvalidArgumentException("Operator 'between' requires a two-element array value.");
        }
        $builder->whereRaw(
            "{$columnSql} between ? and ?",
            [$this->castParam($value[0], $field), $this->castParam($value[1], $field)],
        );
    }

    /**
     * Produce the SQL fragment that extracts a JSONB key in the right SQL type
     * for the field. `data->>'slug'` returns text; casting it to numeric/date
     * gives the comparison the right semantics in Postgres.
     *
     * System fields (sys_created_at, sys_updated_at) bypass JSONB and read the
     * real timestamp columns on the records table — they're always present and
     * never stored inside the data blob.
     *
     * @param  array<string, mixed>  $field
     */
    private function jsonExtract(string $column, array $field): string
    {
        if (isset($field['_system_column'])) {
            // The column name comes from a static whitelist (see systemField),
            // never from user input — safe to inline.
            return $field['_system_column'];
        }

        $slug = $this->safeSlug($field['slug']);
        $base = "{$column}->>'{$slug}'";

        return match ($field['type']) {
            'number', 'currency', 'rating', 'slider' => "({$base})::numeric",
            'boolean' => "({$base})::boolean",
            'date' => "({$base})::date",
            'datetime' => "({$base})::timestamptz",
            default => $base,
        };
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function castParam(mixed $value, array $field): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($field['type']) {
            'number', 'currency', 'rating', 'slider' => is_numeric($value) ? $value + 0 : $value,
            'boolean' => (bool) $value,
            default => is_scalar($value) ? (string) $value : $value,
        };
    }

    /**
     * @param  array<string, mixed>  $expr
     * @param  array<string, mixed>  $context
     */
    private function resolveValue(array $expr, array $context): mixed
    {
        if (array_key_exists('value', $expr)) {
            return $expr['value'];
        }

        if (isset($expr['value_expression'])) {
            return $this->expressions->resolve($expr['value_expression'], $context);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function findObject(array $manifest, string $objectId): array
    {
        foreach ($manifest['objects'] ?? [] as $object) {
            if ($object['id'] === $objectId) {
                return $object;
            }
        }
        throw new RuntimeException("Object '{$objectId}' not found in manifest.");
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    private function findField(array $object, string $fieldId): array
    {
        $sys = $this->systemField($fieldId);
        if ($sys !== null) {
            return $sys;
        }
        foreach ($object['fields'] as $field) {
            if ($field['id'] === $fieldId) {
                return $field;
            }
        }
        throw new RuntimeException("Field '{$fieldId}' not found in object '{$object['slug']}'.");
    }

    /**
     * Return the synthetic descriptor for a system field id, or null if the id
     * is not in the system namespace. System fields are always present on every
     * object and map directly to columns on the records table.
     *
     * @return array<string, mixed>|null
     */
    public static function systemField(string $fieldId): ?array
    {
        return match ($fieldId) {
            'sys_created_at' => [
                'id' => 'sys_created_at',
                'slug' => 'sys_created_at',
                'name' => 'Created at',
                'type' => 'datetime',
                'system' => true,
                '_system_column' => 'created_at',
            ],
            'sys_updated_at' => [
                'id' => 'sys_updated_at',
                'slug' => 'sys_updated_at',
                'name' => 'Updated at',
                'type' => 'datetime',
                'system' => true,
                '_system_column' => 'updated_at',
            ],
            default => null,
        };
    }

    /**
     * Defensive: the manifest validator already enforces that slugs match
     * ^[a-z][a-z0-9_]*$, but inlining a slug into a SQL string warrants a
     * second-line guard against an upstream bypass.
     */
    private function safeSlug(string $slug): string
    {
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $slug)) {
            throw new InvalidArgumentException("Unsafe field slug '{$slug}'.");
        }

        return $slug;
    }
}
