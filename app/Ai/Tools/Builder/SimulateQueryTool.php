<?php

namespace App\Ai\Tools\Builder;

use App\Models\App;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\RecordQueryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Runs a manifest `query` block against the live records, so Claude can verify
 * a filter/sort/aggregation works before bundling it into a table or stat
 * block in a patch. Operates against the in-progress draft when one exists
 * (so Claude can simulate a query referencing a field it just added), else
 * the live manifest.
 */
class SimulateQueryTool implements Tool
{
    private const SAMPLE_LIMIT = 3;

    public function __construct(
        private App $appModel,
        private AppManifestService $manifestService,
        private RecordQueryService $records,
        /**
         * Optional companion holding the running draft for the current turn.
         * Lets simulate_query reference objects/fields that were added in
         * the same turn but not yet persisted.
         */
        private ?ProposeChangeTool $proposeTool = null,
    ) {}

    public function name(): string
    {
        return 'simulate_query';
    }

    public function description(): string
    {
        return <<<'DESC'
Execute a manifest query block (the same shape that goes inside data_source or
stat.query) against the App's live records. Use this when you are about to
propose a table or stat block and want to confirm:
  - the filter actually returns rows (not zero)
  - the field_ids are correct
  - sum/avg/min/max work on the chosen field

Returns {count, sample_rows, aggregation_value?, errors?}. If errors is
present, fix the query before calling propose_change.
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema
                ->object()
                ->description('Query block: {object_id, filter?, sort?, limit?, offset?}.')
                ->required(),
            'aggregation' => $schema
                ->string()
                ->description('Optional aggregation to compute: count|sum|avg|min|max|distinct_count|median|p90|p95. If set, also returns aggregation_value. Use this to verify a stat/metric_grid/gauge/progress KPI before proposing it.'),
            'field_id' => $schema
                ->string()
                ->description('Required for every aggregation except count. distinct_count takes any field; sum|avg|min|max|median|p90|p95 take a numeric field.'),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->all();
        $query = $args['query'] ?? null;
        $aggregation = $args['aggregation'] ?? null;
        $fieldId = $args['field_id'] ?? null;

        if (! is_array($query) || ! isset($query['object_id'])) {
            return json_encode([
                'errors' => [['code' => 'bad_input', 'message' => 'query.object_id is required']],
            ], JSON_THROW_ON_ERROR);
        }

        $manifest = $this->proposeTool?->currentManifest()
            ?? $this->manifestService->getActiveManifest($this->appModel);
        if ($manifest === null) {
            return json_encode([
                'errors' => [['code' => 'no_manifest', 'message' => 'App has no active manifest yet']],
            ], JSON_THROW_ON_ERROR);
        }

        try {
            $resultQuery = array_merge($query, ['limit' => self::SAMPLE_LIMIT]);
            $rows = $this->records->query($this->appModel, $resultQuery, $manifest);
            $count = $this->records->aggregate($this->appModel, $query, 'count', null, $manifest);

            $response = [
                'count' => $count,
                'sample_rows' => $rows->map(fn ($r) => ['id' => $r->id, 'data' => $r->data])->values()->all(),
            ];

            if ($aggregation !== null && $aggregation !== 'count') {
                $response['aggregation'] = $aggregation;
                $response['field_id'] = $fieldId;
                $response['aggregation_value'] = $this->records->aggregate(
                    $this->appModel,
                    $query,
                    $aggregation,
                    $fieldId,
                    $manifest,
                );
            } elseif ($aggregation === 'count') {
                $response['aggregation'] = 'count';
                $response['aggregation_value'] = $count;
            }

            return json_encode($response, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return json_encode([
                'errors' => [[
                    'code' => 'query_failed',
                    'message' => $e->getMessage(),
                ]],
            ], JSON_THROW_ON_ERROR);
        }
    }
}
