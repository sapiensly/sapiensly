<?php

namespace App\Ai\Tools\Runtime\Agent;

use App\Models\App;
use App\Services\Records\BlockDataResolver;
use App\Services\Records\InMemoryAggregator;
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
    /** Every aggregation; all except count require a field_id. */
    private const ALL_AGGS = ['count', 'sum', 'avg', 'min', 'max', 'distinct_count', 'median', 'p90', 'p95'];

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
        private InMemoryAggregator $aggregator = new InMemoryAggregator,
    ) {}

    public function name(): string
    {
        return 'aggregate_object';
    }

    public function description(): string
    {
        return <<<'DESC'
Compute one aggregate over a data object this assistant can read: count,
distinct_count (unique values of any field), sum/avg/min/max, or median/p90/p95
(percentiles) of a numeric field. Call describe_capabilities first for object and
field ids. Returns { aggregation, value }, or { aggregation, group_by,
groups: [{group, value}] } when group_by is set (break down by a field, with an
optional date bucket — "sum amount by month", "median value by category" in one
call). Add group_by_2 for a pivot/matrix → groups: [{group, group2, value}] (e.g.
revenue by region AND month). Works for internal and connected (external) objects.
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'object_id' => $schema->string()
                ->description('The object to aggregate (from describe_capabilities).')
                ->required(),
            'aggregation' => $schema->string()
                ->description('count | distinct_count | sum | avg | min | max | median | p90 | p95.')
                ->required(),
            'field_id' => $schema->string()
                ->description('Required for every aggregation except count. distinct_count takes any field; sum/avg/min/max/median/p90/p95 take a numeric field.'),
            'filter' => $schema->object()
                ->description('Optional filter_expression to narrow the rows aggregated (supports {op: related, ...} relation traversal).'),
            'search' => $schema->string()
                ->description('Optional free-text search across the object\'s text fields (case-insensitive).'),
            'group_by' => $schema->string()
                ->description('Optional field id to break the result down by. Returns groups: [{group, value}].'),
            'group_by_2' => $schema->string()
                ->description('Optional SECOND field id for a pivot/matrix. Returns groups: [{group, group2, value}] — e.g. revenue by region AND by month.'),
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
        if (! in_array($aggregation, self::ALL_AGGS, true)) {
            return json_encode(['error' => 'aggregation must be one of: '.implode(', ', self::ALL_AGGS).'.'], JSON_THROW_ON_ERROR);
        }
        if ($aggregation !== 'count' && ! is_string($fieldId)) {
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
        $groupBy2 = is_string($args['group_by_2'] ?? null) ? $args['group_by_2'] : null;
        $bucket = is_string($args['bucket'] ?? null) ? $args['bucket'] : null;

        try {
            if ($groupBy !== null) {
                $groups = $isConnected
                    ? $this->groupedConnected($object, $query, $aggregation, $fieldId, $groupBy, $bucket, $groupBy2)
                    : $this->records->groupedAggregate($this->appModel, $query, $aggregation, $fieldId, $groupBy, $bucket, $this->manifest, $this->context, secondGroupFieldId: $groupBy2);

                return json_encode(['aggregation' => $aggregation, 'group_by' => $groupBy, 'group_by_2' => $groupBy2, 'bucket' => $bucket, 'groups' => $groups], JSON_THROW_ON_ERROR);
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
     * Aggregate a connected object over its mapped passthrough rows via the
     * shared in-memory aggregator (no SQL store to push the aggregate into).
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $query
     */
    private function aggregateConnected(array $object, array $query, string $aggregation, ?string $fieldId): int|float
    {
        $rows = $this->blockData->queryObject($this->appModel, $query, $this->manifest, $this->context);
        $slug = $fieldId !== null ? $this->fieldSlug($object, $fieldId) : null;

        return $this->aggregator->aggregate($rows, $aggregation, $slug);
    }

    /**
     * Grouped aggregate for a connected object, folded in-memory over its mapped
     * passthrough rows. Mirrors the internal groupedAggregate shape.
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $query
     * @return list<array{group: mixed, value: int|float}>
     */
    private function groupedConnected(array $object, array $query, string $aggregation, ?string $fieldId, string $groupBy, ?string $bucket, ?string $groupBy2 = null): array
    {
        $rows = $this->blockData->queryObject($this->appModel, $query, $this->manifest, $this->context);
        $groupSlug = $this->fieldSlug($object, $groupBy);
        $aggSlug = $fieldId !== null ? $this->fieldSlug($object, $fieldId) : null;
        $group2Slug = $groupBy2 !== null ? $this->fieldSlug($object, $groupBy2) : null;

        return $this->aggregator->grouped($rows, $aggregation, $aggSlug, (string) $groupSlug, $bucket, secondGroupSlug: $group2Slug);
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
