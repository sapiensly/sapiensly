<?php

namespace App\Services\Records;

use App\Models\App;
use App\Models\Record;
use App\Services\Apps\AppAccessContext;
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
 *   related — traverse a relation: {op: related, field_id: <relation field>,
 *     condition: <filter_expression on the related object>}. Works for belongs_to
 *     and has_many; nestable up to MAX_RELATION_DEPTH.
 *
 * A query block may also carry `search` (a string): a case-insensitive match
 * across the object's text fields, ANDed across whitespace-separated terms; and
 * `expand` (a list of belongs_to relation field ids): resolves each related
 * record inline onto the result rows' transient `expanded` map (batched, no
 * N+1; access- and field-hiding-safe).
 *
 * Supported field types for filtering: string, long_text, number, currency,
 * boolean, date, datetime, single_select. multi_select and relation are
 * read-only from the JSONB blob for MVP — filtering on them is best-effort
 * (relation traversal goes through the `related` operator).
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

        $this->applyConstraints($builder, $query, $object, $objectId, $app, $manifest, $context);

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

        if (isset($query['expand']) && is_array($query['expand']) && $query['expand'] !== []) {
            $this->expandRelations($app, $records, $query['expand'], $object, $manifest, $context);
        }

        return $records;
    }

    /**
     * Resolve belongs_to relations inline on a result set: for each requested
     * relation field, batch-load the related records (one query per target
     * object, no N+1) and attach them to each row's transient `expanded` map as
     * `{id, data}` (or null when the FK is empty / the related record is not
     * visible). Honours the target object's row_filter (inaccessible relateds
     * resolve to null) and strips its hidden fields, so expansion never leaks
     * what a direct read would hide.
     *
     * has_many relations are skipped — a flat row can't carry a child list; query
     * the child object directly (optionally with a `related` filter) instead.
     *
     * @param  Collection<int, Record>  $records
     * @param  list<string>  $expandFieldIds
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     */
    private function expandRelations(App $app, Collection $records, array $expandFieldIds, array $object, array $manifest, array $context): void
    {
        $access = $context['__access'] ?? null;

        foreach ($expandFieldIds as $fieldId) {
            if (! is_string($fieldId)) {
                continue;
            }

            $field = $this->findField($object, $fieldId);
            if (($field['type'] ?? null) !== 'relation' || ($field['cardinality'] ?? 'many_to_one') !== 'many_to_one') {
                // Only belongs_to expands inline; mark others null so the caller
                // gets a consistent shape rather than a missing key.
                foreach ($records as $r) {
                    $r->expanded[$fieldId] = null;
                }

                continue;
            }

            $targetId = $field['target_object_id'] ?? null;
            if ($targetId === null) {
                continue;
            }

            $slug = $field['slug'];
            $ids = $records
                ->map(fn (Record $r) => $r->data[$slug] ?? null)
                ->filter(fn ($v) => $v !== null && $v !== '')
                ->map(fn ($v) => (string) $v)
                ->unique()
                ->values()
                ->all();

            if ($ids === []) {
                foreach ($records as $r) {
                    $r->expanded[$fieldId] = null;
                }

                continue;
            }

            $targetObject = $this->findObject($manifest, $targetId);
            $builder = $this->scopeForObject($app, $targetId)->whereIn('id', $ids);
            $this->applyAccessFilter($builder, $targetObject, $targetId, $app, $manifest, $context);

            /** @var Collection<int, Record> $related */
            $related = $builder->get();
            $this->derived->enrich($app, $targetObject, $related, $manifest);

            $hidden = $access instanceof AppAccessContext ? $access->hiddenFieldSlugs($targetId) : [];
            $byId = $related->keyBy('id');

            foreach ($records as $r) {
                $fk = $r->data[$slug] ?? null;
                $rel = ($fk !== null && $fk !== '') ? $byId->get((string) $fk) : null;
                if ($rel === null) {
                    $r->expanded[$fieldId] = null;

                    continue;
                }
                $data = $rel->data;
                foreach ($hidden as $h) {
                    unset($data[$h]);
                }
                $r->expanded[$fieldId] = ['id' => $rel->id, 'data' => $data];
            }
        }
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

        $this->applyConstraints($builder, $query, $object, $objectId, $app, $manifest, $context);

        if ($aggregation === 'count') {
            return $builder->count();
        }

        if ($fieldId === null) {
            throw new InvalidArgumentException("Aggregation '{$aggregation}' requires field_id.");
        }

        $field = $this->findField($object, $fieldId);

        // Derived fields (formula/lookup/rollup) have no SQL column to aggregate
        // over — their values are computed in PHP after the rows load. So we run
        // the (already filter+access-scoped) query, enrich the derived values,
        // then fold them in memory. This is what lets a dashboard sum/avg/chart a
        // rollup or formula field (e.g. a comanda total) instead of only stored
        // number/currency columns.
        if (in_array($field['type'], ['formula', 'lookup', 'rollup'], true)) {
            return $this->aggregateDerived($app, $object, $builder, $aggregation, $field, $manifest);
        }

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

    /**
     * Aggregate a derived (formula/lookup/rollup) field in PHP: materialize the
     * scoped rows, enrich their derived values, then fold the numeric results.
     * `count` never reaches here (it's handled before field resolution).
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $manifest
     */
    private function aggregateDerived(
        App $app,
        array $object,
        Builder $builder,
        string $aggregation,
        array $field,
        array $manifest,
    ): int|float {
        /** @var Collection<int, Record> $rows */
        $rows = $builder->get();
        $this->derived->enrich($app, $object, $rows, $manifest);

        $slug = $field['slug'];
        $values = $rows
            ->map(fn (Record $r) => $r->data[$slug] ?? null)
            ->filter(fn ($v) => is_numeric($v))
            ->map(fn ($v) => (float) $v)
            ->values();

        if ($values->isEmpty()) {
            return $aggregation === 'avg' ? 0 : 0;
        }

        return match ($aggregation) {
            'sum' => (float) $values->sum(),
            'avg' => (float) $values->avg(),
            'min' => (float) $values->min(),
            'max' => (float) $values->max(),
            default => throw new InvalidArgumentException("Unknown aggregation '{$aggregation}'."),
        };
    }

    /**
     * Fetch a single record by id, scoped to the app + object (RLS applies),
     * with derived (formula/lookup/rollup) fields enriched. Null if not found.
     *
     * The access row_filter (from $context['__access']) is ANDed in, so a record
     * the user's role may not see resolves to null — this is the write-path
     * re-check that closes the update/delete privilege-escalation hole.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     */
    public function find(App $app, string $objectId, string $recordId, array $manifest, array $context = []): ?Record
    {
        $object = $this->findObject($manifest, $objectId);

        $builder = $this->scopeForObject($app, $objectId)->whereKey($recordId);
        $this->applyAccessFilter($builder, $object, $objectId, $app, $manifest, $context);

        $record = $builder->first();
        if ($record === null) {
            return null;
        }

        $this->derived->enrich($app, $object, $record->newCollection([$record]), $manifest);

        return $record;
    }

    /**
     * Count the records matching a query (filter + access scope) without
     * materialising any rows. Shares the exact scope/filter/access path with
     * query() and aggregate(), so the total honours RLS and the role row_filter.
     *
     * @param  array<string, mixed>  $query  Query block (object_id + optional filter)
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     */
    public function count(App $app, array $query, array $manifest, array $context = []): int
    {
        $objectId = $query['object_id'] ?? null;
        if ($objectId === null) {
            throw new InvalidArgumentException('query.object_id is required.');
        }

        $object = $this->findObject($manifest, $objectId);
        $builder = $this->scopeForObject($app, $objectId);

        $this->applyConstraints($builder, $query, $object, $objectId, $app, $manifest, $context);

        return $builder->count();
    }

    /**
     * Like query(), but also returns the total number of matching records (the
     * full count ignoring limit/offset) and whether more rows follow the page.
     * Lets a consumer page deterministically instead of guessing from a short
     * final page.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     * @return array{records: Collection<int, Record>, total: int, has_more: bool}
     */
    public function queryWithMeta(App $app, array $query, array $manifest, array $context = []): array
    {
        $total = $this->count($app, $query, $manifest, $context);
        $records = $this->query($app, $query, $manifest, $context);
        $offset = (int) ($query['offset'] ?? 0);

        return [
            'records' => $records,
            'total' => $total,
            'has_more' => ($offset + $records->count()) < $total,
        ];
    }

    /**
     * Aggregate a metric broken down by a grouping field — the one query shape
     * the scalar aggregate() cannot express. Returns one bucket per distinct
     * group value: `[{group, value}]`. Optionally buckets a date/datetime group
     * field by day/week/month/quarter/year (date_trunc) so "sum revenue by month"
     * is a single call instead of N filtered ones.
     *
     * Stored numeric metrics (and count) are folded in SQL with GROUP BY; a
     * derived (formula/lookup/rollup) metric has no SQL column, so those rows are
     * grouped and folded in PHP after enrichment (mirrors aggregate()).
     *
     * @param  array<string, mixed>  $query  Query block (object_id + optional filter)
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     * @return list<array{group: mixed, value: int|float}>
     */
    public function groupedAggregate(
        App $app,
        array $query,
        string $aggregation,
        ?string $fieldId,
        string $groupFieldId,
        ?string $bucket,
        array $manifest,
        array $context = [],
        int $limit = 100,
    ): array {
        $objectId = $query['object_id'] ?? null;
        if ($objectId === null) {
            throw new InvalidArgumentException('query.object_id is required.');
        }

        $object = $this->findObject($manifest, $objectId);
        $groupField = $this->findField($object, $groupFieldId);

        if ($bucket !== null && ! in_array($groupField['type'], ['date', 'datetime'], true)) {
            throw new InvalidArgumentException('bucket is only valid for a date or datetime group field.');
        }

        $aggField = null;
        if ($aggregation !== 'count') {
            if ($fieldId === null) {
                throw new InvalidArgumentException("Aggregation '{$aggregation}' requires field_id.");
            }
            $aggField = $this->findField($object, $fieldId);
        }

        $builder = $this->scopeForObject($app, $objectId);
        $this->applyConstraints($builder, $query, $object, $objectId, $app, $manifest, $context);

        // A derived metric has no SQL column to fold — group it in PHP.
        if ($aggField !== null && in_array($aggField['type'], ['formula', 'lookup', 'rollup'], true)) {
            return $this->groupedAggregateDerived($app, $object, $builder, $aggregation, $aggField, $groupField, $bucket, $manifest, $limit);
        }

        if ($aggField !== null && ! in_array($aggField['type'], ['number', 'currency', 'rating', 'slider'], true)) {
            throw new InvalidArgumentException(
                "Aggregation '{$aggregation}' requires a numeric field, got '{$aggField['type']}'.",
            );
        }

        $groupExpr = $bucket !== null
            ? $this->dateBucketExpr($groupField, $bucket)
            : $this->jsonExtract('data', $groupField);

        $aggSql = $aggregation === 'count'
            ? 'count(*)'
            : match ($aggregation) {
                'sum' => 'sum('.$this->jsonExtract('data', $aggField).')',
                'avg' => 'avg('.$this->jsonExtract('data', $aggField).')',
                'min' => 'min('.$this->jsonExtract('data', $aggField).')',
                'max' => 'max('.$this->jsonExtract('data', $aggField).')',
                default => throw new InvalidArgumentException("Unknown aggregation '{$aggregation}'."),
            };

        $rows = $builder->toBase()
            ->selectRaw("{$groupExpr} as grp, {$aggSql} as val")
            ->groupByRaw($groupExpr)
            ->orderByRaw($groupExpr)
            ->limit($limit)
            ->get();

        return $rows->map(fn ($r) => [
            'group' => $r->grp,
            'value' => $aggregation === 'count' ? (int) $r->val : (float) $r->val,
        ])->all();
    }

    /**
     * PHP-side grouped fold for a derived metric: materialise the scoped rows,
     * enrich derived values, bucket by the group value, then fold each bucket.
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $aggField
     * @param  array<string, mixed>  $groupField
     * @param  array<string, mixed>  $manifest
     * @return list<array{group: mixed, value: int|float}>
     */
    private function groupedAggregateDerived(
        App $app,
        array $object,
        Builder $builder,
        string $aggregation,
        array $aggField,
        array $groupField,
        ?string $bucket,
        array $manifest,
        int $limit,
    ): array {
        /** @var Collection<int, Record> $rows */
        $rows = $builder->get();
        $this->derived->enrich($app, $object, $rows, $manifest);

        $aggSlug = $aggField['slug'];
        $buckets = [];
        foreach ($rows as $row) {
            $group = $this->groupValueFor($row, $groupField, $bucket);
            $value = $row->data[$aggSlug] ?? null;
            if (! is_numeric($value)) {
                continue;
            }
            $buckets[$group] ??= [];
            $buckets[$group][] = (float) $value;
        }

        ksort($buckets);
        $out = [];
        foreach ($buckets as $group => $values) {
            $out[] = [
                'group' => $group === '' ? null : $group,
                'value' => match ($aggregation) {
                    'sum' => array_sum($values),
                    'avg' => array_sum($values) / count($values),
                    'min' => min($values),
                    'max' => max($values),
                    default => throw new InvalidArgumentException("Unknown aggregation '{$aggregation}'."),
                },
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Resolve a record's group key for the PHP-side fold, applying the same
     * day/week/month/quarter/year truncation that date_trunc applies in SQL.
     *
     * @param  array<string, mixed>  $groupField
     */
    private function groupValueFor(Record $record, array $groupField, ?string $bucket): string
    {
        $raw = isset($groupField['_system_column'])
            ? (string) ($record->{$groupField['_system_column']} ?? '')
            : (string) ($record->data[$groupField['slug']] ?? '');

        if ($bucket === null || $raw === '') {
            return $raw;
        }

        $ts = strtotime($raw);
        if ($ts === false) {
            return $raw;
        }

        return match ($bucket) {
            'day' => date('Y-m-d', $ts),
            'week' => date('o-\WW', $ts),
            'month' => date('Y-m', $ts),
            'quarter' => date('Y', $ts).'-Q'.(int) ceil((int) date('n', $ts) / 3),
            'year' => date('Y', $ts),
            default => $raw,
        };
    }

    /**
     * SQL date_trunc fragment for bucketing a date/datetime group field. The
     * bucket keyword comes from a fixed whitelist (never user input inlined raw)
     * and the slug is re-validated, so the raw fragment is safe.
     *
     * @param  array<string, mixed>  $field
     */
    private function dateBucketExpr(array $field, string $bucket): string
    {
        if (! in_array($bucket, ['day', 'week', 'month', 'quarter', 'year'], true)) {
            throw new InvalidArgumentException("Unknown bucket '{$bucket}'.");
        }

        if (isset($field['_system_column'])) {
            return "date_trunc('{$bucket}', {$field['_system_column']})";
        }

        $slug = $this->safeSlug($field['slug']);

        return "date_trunc('{$bucket}', (data->>'{$slug}')::timestamptz)";
    }

    private function scopeForObject(App $app, string $objectId): Builder
    {
        return Record::query()
            ->where('app_id', $app->id)
            ->where('object_definition_id', $objectId);
    }

    /** How deep `related` filters may nest before bailing (cycle/cost guard). */
    private const MAX_RELATION_DEPTH = 3;

    /** Cap on related-record ids resolved for one relation hop (cost guard). */
    private const RELATED_MATCH_CAP = 5000;

    /**
     * Apply the full constraint set of a query block to a builder: the filter
     * expression, the cross-field text search, and the role-scoped access filter.
     * The single seam every read path (query/aggregate/count/grouped) routes
     * through, so they stay consistent.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     */
    private function applyConstraints(Builder $builder, array $query, array $object, string $objectId, App $app, array $manifest, array $context): void
    {
        if (isset($query['filter'])) {
            $this->applyFilter($builder, $query['filter'], $object, $app, $manifest, $context);
        }

        if (isset($query['search']) && is_string($query['search']) && trim($query['search']) !== '') {
            $this->applySearch($builder, $object, $query['search']);
        }

        $this->applyAccessFilter($builder, $object, $objectId, $app, $manifest, $context);
    }

    /**
     * AND the user's role-scoped row_filter (a manifest filter_expression carried
     * on the AppAccessContext in $context['__access']) onto a query. A no-op when
     * no access context is present (callers that don't enforce policy) or the
     * user's role is unrestricted on this object.
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     */
    private function applyAccessFilter(Builder $builder, array $object, string $objectId, App $app, array $manifest, array $context): void
    {
        $access = $context['__access'] ?? null;
        if (! $access instanceof AppAccessContext) {
            return;
        }

        $rowFilter = $access->rowFilter($objectId);
        if ($rowFilter !== null) {
            $this->applyFilter($builder, $rowFilter, $object, $app, $manifest, $context);
        }
    }

    /**
     * OR a case-insensitive match of each search term across the object's text
     * fields onto the builder. Terms are ANDed (every term must hit some field),
     * each term ORed across the fields — closer to "contains all these words"
     * than a single blob match. An object with no text fields matches nothing.
     *
     * @param  array<string, mixed>  $object
     */
    private function applySearch(Builder $builder, array $object, string $search): void
    {
        $fields = $this->textSearchFields($object);
        if ($fields === []) {
            $builder->whereRaw('1=0');

            return;
        }

        $terms = preg_split('/\s+/', trim($search)) ?: [];
        foreach ($terms as $term) {
            if ($term === '') {
                continue;
            }
            $builder->where(function (Builder $q) use ($fields, $term) {
                foreach ($fields as $field) {
                    $q->orWhereRaw('('.$this->jsonExtract('data', $field).')::text ilike ?', ['%'.$term.'%']);
                }
            });
        }
    }

    /**
     * The fields of an object that text search scans: free text and label-ish
     * fields. Numeric/date/boolean fields are excluded — searching them by
     * substring is meaningless.
     *
     * @param  array<string, mixed>  $object
     * @return list<array<string, mixed>>
     */
    private function textSearchFields(array $object): array
    {
        $types = ['string', 'long_text', 'single_select', 'multi_select', 'email', 'url', 'phone'];
        $out = [];
        foreach ($object['fields'] ?? [] as $field) {
            if (in_array($field['type'] ?? null, $types, true)) {
                $out[] = $field;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $expr
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     */
    private function applyFilter(Builder $builder, array $expr, array $object, App $app, array $manifest, array $context): void
    {
        $op = $expr['op'] ?? null;

        if ($op === 'and') {
            $builder->where(function (Builder $q) use ($expr, $object, $app, $manifest, $context) {
                foreach ($expr['conditions'] as $cond) {
                    $this->applyFilter($q, $cond, $object, $app, $manifest, $context);
                }
            });

            return;
        }

        if ($op === 'or') {
            $builder->where(function (Builder $q) use ($expr, $object, $app, $manifest, $context) {
                foreach ($expr['conditions'] as $cond) {
                    $q->orWhere(function (Builder $inner) use ($cond, $object, $app, $manifest, $context) {
                        $this->applyFilter($inner, $cond, $object, $app, $manifest, $context);
                    });
                }
            });

            return;
        }

        if ($op === 'not') {
            $builder->whereNot(function (Builder $q) use ($expr, $object, $app, $manifest, $context) {
                $this->applyFilter($q, $expr['condition'], $object, $app, $manifest, $context);
            });

            return;
        }

        if ($op === 'related') {
            $this->applyRelatedFilter($builder, $expr, $object, $app, $manifest, $context);

            return;
        }

        $field = $this->findField($object, $expr['field_id']);
        $value = $this->resolveValue($expr, $context);
        $columnSql = $this->jsonExtract('data', $field);

        // A value-based condition whose value resolved to empty (e.g. an unset
        // {{params.x}} from a page filter the user hasn't filled) is a no-op —
        // skip it so the unfiltered set shows instead of matching `field = ''`.
        $valueOps = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'contains', 'starts_with', 'ends_with', 'between'];
        if (in_array($op, $valueOps, true)
            && ($value === null || $value === '' || (is_array($value) && $value === []))) {
            return;
        }

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
     * Traverse a relation in a filter: keep base records whose related record(s)
     * satisfy a sub-condition. Resolves the matching related ids in one extra
     * query, then constrains the base set by them — no correlated subquery, and
     * it reuses applyFilter/access so the sub-condition supports the full operator
     * set (including nested `related`, bounded by MAX_RELATION_DEPTH).
     *
     *   many_to_one (belongs_to): base.data->>'rel' must point at a matching target.
     *   one_to_many (has_many):   base.id must be referenced by a matching child.
     *
     * Wrap in `{op: not, condition: {op: related, ...}}` for "has no matching related".
     *
     * @param  array<string, mixed>  $expr
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     */
    private function applyRelatedFilter(Builder $builder, array $expr, array $object, App $app, array $manifest, array $context): void
    {
        $depth = (int) ($context['__rel_depth'] ?? 0);
        if ($depth >= self::MAX_RELATION_DEPTH) {
            throw new InvalidArgumentException('Relation filters nested too deeply (max '.self::MAX_RELATION_DEPTH.').');
        }

        $field = $this->findField($object, $expr['field_id']);
        if (($field['type'] ?? null) !== 'relation') {
            throw new InvalidArgumentException("Operator 'related' requires a relation field, got '{$field['type']}'.");
        }

        $condition = $expr['condition'] ?? null;
        if (! is_array($condition)) {
            throw new InvalidArgumentException("Operator 'related' requires a 'condition' sub-filter.");
        }

        $targetId = $field['target_object_id'] ?? null;
        if ($targetId === null) {
            throw new InvalidArgumentException("Relation field '{$field['slug']}' has no target_object_id.");
        }

        $targetObject = $this->findObject($manifest, $targetId);
        $cardinality = $field['cardinality'] ?? 'many_to_one';

        $childContext = $context;
        $childContext['__rel_depth'] = $depth + 1;

        $ids = $this->relatedMatchIds($app, $targetObject, $targetId, $cardinality, $field, $condition, $manifest, $childContext);

        if ($cardinality === 'one_to_many') {
            $idField = self::systemField('id');
            $this->applyIn($builder, $this->jsonExtract('data', $idField), $idField, $ids, negate: false);

            return;
        }

        $this->applyIn($builder, $this->jsonExtract('data', $field), $field, $ids, negate: false);
    }

    /**
     * Resolve the related-record ids for a relation hop: the ids a base record
     * must reference (belongs_to) or be referenced by (has_many) for its related
     * side to satisfy $condition. Bounded by RELATED_MATCH_CAP.
     *
     * @param  array<string, mixed>  $targetObject
     * @param  array<string, mixed>  $relationField
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     * @return list<string>
     */
    private function relatedMatchIds(App $app, array $targetObject, string $targetId, string $cardinality, array $relationField, array $condition, array $manifest, array $context): array
    {
        $sub = $this->scopeForObject($app, $targetId);
        $this->applyFilter($sub, $condition, $targetObject, $app, $manifest, $context);
        $this->applyAccessFilter($sub, $targetObject, $targetId, $app, $manifest, $context);

        if ($cardinality === 'one_to_many') {
            // Matching children → the base/parent ids they carry on the inverse FK.
            $inverseId = $relationField['inverse_field_id'] ?? null;
            if ($inverseId === null) {
                throw new InvalidArgumentException("Relation '{$relationField['slug']}' has no inverse_field_id to traverse.");
            }
            $inverseField = $this->findField($targetObject, $inverseId);
            $expr = $this->jsonExtract('data', $inverseField);

            return $sub->toBase()
                ->selectRaw("{$expr} as v")
                ->limit(self::RELATED_MATCH_CAP)
                ->get()
                ->pluck('v')
                ->filter(fn ($v) => $v !== null && $v !== '')
                ->map(fn ($v) => (string) $v)
                ->unique()
                ->values()
                ->all();
        }

        // belongs_to: the matching target records' own ids.
        return $sub->toBase()
            ->limit(self::RELATED_MATCH_CAP)
            ->get(['id'])
            ->pluck('id')
            ->map(fn ($v) => (string) $v)
            ->all();
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
            'id' => [
                'id' => 'id',
                'slug' => 'id',
                'name' => 'Record ID',
                'type' => 'string',
                'system' => true,
                '_system_column' => 'id',
            ],
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
