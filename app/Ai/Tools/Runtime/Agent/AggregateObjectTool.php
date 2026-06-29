<?php

namespace App\Ai\Tools\Runtime\Agent;

use App\Models\App;
use App\Services\Records\BlockDataResolver;
use App\Services\Records\RecordQueryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Runtime agent read tool (builder power #3). Computes a single aggregate
 * (count/sum/avg/min/max) over an object the agent may read. Internal objects
 * aggregate in SQL via RecordQueryService; connected objects (power #2) have no
 * SQL store, so they aggregate in-memory over the mapped passthrough rows —
 * source-agnostic to the agent. The object_id is validated against the read
 * grant, so the tool cannot reach an ungranted object.
 */
class AggregateObjectTool implements Tool
{
    private const NUMERIC_AGGS = ['sum', 'avg', 'min', 'max'];

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $readableObjectIds
     * @param  array<string, mixed>  $context  carries __access so reads honour row_filter + hidden fields
     */
    public function __construct(
        private App $appModel,
        private array $manifest,
        private array $readableObjectIds,
        private RecordQueryService $records,
        private BlockDataResolver $blockData,
        private array $context = [],
    ) {}

    public function name(): string
    {
        return 'aggregate_object';
    }

    public function description(): string
    {
        return <<<'DESC'
Compute one aggregate over a data object this assistant can read: count, or
sum/avg/min/max of a numeric field. Call describe_capabilities first for object
and field ids. Returns { aggregation, value }, or { aggregation, group_by,
groups: [{group, value}] } when group_by is set (break down by a field, with an
optional date bucket — "sum amount by month/by category" in one call). Works for
internal and connected (external) objects.
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'object_id' => $schema->string()
                ->description('The object to aggregate (from describe_capabilities).')
                ->required(),
            'aggregation' => $schema->string()
                ->description('count | sum | avg | min | max.')
                ->required(),
            'field_id' => $schema->string()
                ->description('Required for sum/avg/min/max — the numeric field to aggregate.'),
            'filter' => $schema->object()
                ->description('Optional filter_expression to narrow the rows aggregated (supports {op: related, ...} relation traversal).'),
            'search' => $schema->string()
                ->description('Optional free-text search across the object\'s text fields (case-insensitive).'),
            'group_by' => $schema->string()
                ->description('Optional field id to break the result down by. Returns groups: [{group, value}].'),
            'bucket' => $schema->string()
                ->description('When group_by is a date/datetime field, truncate it to: day | week | month | quarter | year.'),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->all();
        $objectId = $args['object_id'] ?? null;
        $aggregation = $args['aggregation'] ?? null;
        $fieldId = $args['field_id'] ?? null;

        if (! is_string($objectId) || ! in_array($objectId, $this->readableObjectIds, true)) {
            return json_encode(['error' => 'This object is not available to this assistant.'], JSON_THROW_ON_ERROR);
        }
        if (! in_array($aggregation, ['count', ...self::NUMERIC_AGGS], true)) {
            return json_encode(['error' => 'aggregation must be one of: count, sum, avg, min, max.'], JSON_THROW_ON_ERROR);
        }
        if (in_array($aggregation, self::NUMERIC_AGGS, true) && ! is_string($fieldId)) {
            return json_encode(['error' => "Aggregation '{$aggregation}' requires field_id."], JSON_THROW_ON_ERROR);
        }

        $object = $this->findObject($objectId);
        $isConnected = ($object['source']['type'] ?? 'internal') === 'connected';
        $query = ['object_id' => $objectId];
        if (isset($args['filter'])) {
            $query['filter'] = $args['filter'];
        }
        if (is_string($args['search'] ?? null)) {
            $query['search'] = $args['search'];
        }

        $groupBy = is_string($args['group_by'] ?? null) ? $args['group_by'] : null;
        $bucket = is_string($args['bucket'] ?? null) ? $args['bucket'] : null;

        try {
            if ($groupBy !== null) {
                $groups = $isConnected
                    ? $this->groupedConnected($object, $query, $aggregation, $fieldId, $groupBy, $bucket)
                    : $this->records->groupedAggregate($this->appModel, $query, $aggregation, $fieldId, $groupBy, $bucket, $this->manifest, $this->context);

                return json_encode(['aggregation' => $aggregation, 'group_by' => $groupBy, 'bucket' => $bucket, 'groups' => $groups], JSON_THROW_ON_ERROR);
            }

            $value = $isConnected
                ? $this->aggregateConnected($object, $query, $aggregation, $fieldId)
                : $this->records->aggregate($this->appModel, $query, $aggregation, $fieldId, $this->manifest, $this->context);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR);
        }

        return json_encode(['aggregation' => $aggregation, 'value' => $value], JSON_THROW_ON_ERROR);
    }

    /**
     * Aggregate a connected object in-memory over its mapped passthrough rows —
     * there is no SQL store to push the aggregate into.
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $query
     */
    private function aggregateConnected(array $object, array $query, string $aggregation, ?string $fieldId): int|float
    {
        $rows = $this->blockData->queryObject($this->appModel, $query, $this->manifest, $this->context);

        if ($aggregation === 'count') {
            return count($rows);
        }

        $slug = $this->fieldSlug($object, (string) $fieldId);
        $values = [];
        foreach ($rows as $row) {
            $v = $row['data'][$slug] ?? null;
            if (is_numeric($v)) {
                $values[] = $v + 0;
            }
        }

        if ($values === []) {
            return 0;
        }

        return match ($aggregation) {
            'sum' => array_sum($values),
            'avg' => array_sum($values) / count($values),
            'min' => min($values),
            'max' => max($values),
            default => 0,
        };
    }

    /**
     * Grouped aggregate for a connected object, folded in-memory over its mapped
     * passthrough rows (no SQL store to GROUP BY). Mirrors the internal
     * groupedAggregate shape: [{group, value}].
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $query
     * @return list<array{group: mixed, value: int|float}>
     */
    private function groupedConnected(array $object, array $query, string $aggregation, ?string $fieldId, string $groupBy, ?string $bucket): array
    {
        $rows = $this->blockData->queryObject($this->appModel, $query, $this->manifest, $this->context);

        $groupSlug = $this->fieldSlug($object, $groupBy);
        $aggSlug = $fieldId !== null ? $this->fieldSlug($object, $fieldId) : null;

        $buckets = [];
        foreach ($rows as $row) {
            $key = $this->bucketKey((string) ($row['data'][$groupSlug] ?? ''), $bucket);
            if ($aggregation === 'count') {
                $buckets[$key][] = 1;

                continue;
            }
            $v = $aggSlug !== null ? ($row['data'][$aggSlug] ?? null) : null;
            if (is_numeric($v)) {
                $buckets[$key][] = $v + 0;
            }
        }

        ksort($buckets);
        $out = [];
        foreach ($buckets as $key => $values) {
            $out[] = [
                'group' => $key === '' ? null : $key,
                'value' => match ($aggregation) {
                    'count' => count($values),
                    'sum' => array_sum($values),
                    'avg' => array_sum($values) / count($values),
                    'min' => min($values),
                    'max' => max($values),
                    default => 0,
                },
            ];
        }

        return $out;
    }

    /**
     * Apply day/week/month/quarter/year truncation to a date-ish group value,
     * matching the SQL date_trunc buckets. Returns the raw value when no bucket
     * is requested or the value is not parseable.
     */
    private function bucketKey(string $raw, ?string $bucket): string
    {
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
     * @return array<string, mixed>
     */
    private function findObject(string $objectId): array
    {
        foreach ($this->manifest['objects'] ?? [] as $object) {
            if (($object['id'] ?? null) === $objectId) {
                return $object;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function fieldSlug(array $object, string $fieldId): ?string
    {
        foreach ($object['fields'] ?? [] as $field) {
            if (($field['id'] ?? null) === $fieldId) {
                return $field['slug'] ?? null;
            }
        }

        return null;
    }
}
