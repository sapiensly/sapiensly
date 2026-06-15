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
     */
    public function __construct(
        private App $appModel,
        private array $manifest,
        private array $readableObjectIds,
        private RecordQueryService $records,
        private BlockDataResolver $blockData,
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
and field ids. Returns { aggregation, value }. Works for internal and connected
(external) objects.
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
                ->description('Optional filter_expression to narrow the rows aggregated.'),
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
        $query = ['object_id' => $objectId];
        if (isset($args['filter'])) {
            $query['filter'] = $args['filter'];
        }

        try {
            $value = (($object['source']['type'] ?? 'internal') === 'connected')
                ? $this->aggregateConnected($object, $query, $aggregation, $fieldId)
                : $this->records->aggregate($this->appModel, $query, $aggregation, $fieldId, $this->manifest);
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
        $rows = $this->blockData->queryObject($this->appModel, $query, $this->manifest);

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
