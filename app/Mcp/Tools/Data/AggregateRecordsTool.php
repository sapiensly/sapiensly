<?php

namespace App\Mcp\Tools\Data;

use App\Mcp\Tools\SapiensTool;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\RecordQueryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Aggregate records of an object: count, distinct_count (unique values of any field), sum/avg/min/max, or median/p90/p95 (percentiles) over a numeric field. Optionally broken down by a group_by field (with a date bucket for date/datetime fields) — "sum amount by month", "median resolution time", "distinct customers by plan" in one call — and optionally filtered.')]
class AggregateRecordsTool extends SapiensTool
{
    protected const ABILITY = 'data:read';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'object_id' => ['required', 'string'],
            'aggregation' => ['required', 'in:count,sum,avg,min,max,distinct_count,median,p90,p95'],
            'field_id' => ['nullable', 'string'],
            'filter' => ['sometimes', 'array'],
            'search' => ['sometimes', 'string'],
            'group_by' => ['sometimes', 'string'],
            'group_by_2' => ['sometimes', 'string'],
            'bucket' => ['sometimes', 'in:day,week,month,quarter,year'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ], [
            'aggregation.in' => 'aggregation must be one of: count, sum, avg, min, max, distinct_count, median, p90, p95.',
            'bucket.in' => 'bucket must be one of: day, week, month, quarter, year.',
        ]);

        if ($validated['aggregation'] !== 'count' && empty($validated['field_id'])) {
            return Response::error('field_id is required for every aggregation except count (distinct_count takes any field; sum/avg/min/max/median/p90/p95 take a numeric field).');
        }

        /** @var User $user */
        $user = $request->user();

        try {
            $app = $this->resolveApp($validated['app_slug'], $user);
        } catch (ModelNotFoundException) {
            return Response::error("No app named '{$validated['app_slug']}' is visible to you.");
        }

        $manifest = app(AppManifestService::class)->getActiveManifest($app) ?? [];
        $query = ['object_id' => $validated['object_id']];
        foreach (['filter', 'search'] as $key) {
            if (isset($validated[$key])) {
                $query[$key] = $validated[$key];
            }
        }

        $records = app(RecordQueryService::class);

        try {
            if (! empty($validated['group_by'])) {
                $groups = $records->groupedAggregate(
                    $app,
                    $query,
                    $validated['aggregation'],
                    $validated['field_id'] ?? null,
                    $validated['group_by'],
                    $validated['bucket'] ?? null,
                    $manifest,
                    limit: $validated['limit'] ?? 100,
                    secondGroupFieldId: $validated['group_by_2'] ?? null,
                );

                return Response::json([
                    'aggregation' => $validated['aggregation'],
                    'field_id' => $validated['field_id'] ?? null,
                    'group_by' => $validated['group_by'],
                    'group_by_2' => $validated['group_by_2'] ?? null,
                    'bucket' => $validated['bucket'] ?? null,
                    'groups' => $groups,
                ]);
            }

            $value = $records->aggregate(
                $app,
                $query,
                $validated['aggregation'],
                $validated['field_id'] ?? null,
                $manifest,
            );
        } catch (\Throwable $e) {
            return Response::error('Aggregate failed: '.$e->getMessage());
        }

        return Response::json([
            'aggregation' => $validated['aggregation'],
            'field_id' => $validated['field_id'] ?? null,
            'value' => $value,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app_slug' => $schema->string()->description('The slug of the app.')->required(),
            'object_id' => $schema->string()->description('The object id to aggregate over.')->required(),
            'aggregation' => $schema->string()->enum(['count', 'sum', 'avg', 'min', 'max', 'distinct_count', 'median', 'p90', 'p95'])->description('The aggregation to compute. distinct_count = unique values of any field; median/p90/p95 = percentiles of a numeric field.')->required(),
            'field_id' => $schema->string()->description('The field id to aggregate (required for everything except count; distinct_count accepts any field, the rest require a numeric field).'),
            'filter' => $schema->object()->description('Optional filter block (same shape as query_records, including {op: related, ...} relation traversal).'),
            'search' => $schema->string()->description('Optional free-text search across the object\'s text fields (case-insensitive; terms ANDed).'),
            'group_by' => $schema->string()->description('Optional field id to break the result down by. Returns {groups: [{group, value}]} instead of a single value.'),
            'group_by_2' => $schema->string()->description('Optional SECOND field id for a pivot/matrix. Returns {groups: [{group, group2, value}]} — e.g. sum amount by region AND by month.'),
            'bucket' => $schema->string()->enum(['day', 'week', 'month', 'quarter', 'year'])->description('When group_by is a date/datetime field, truncate it to this bucket.'),
            'limit' => $schema->integer()->description('Max groups to return when group_by is set (default 100).'),
        ];
    }
}
