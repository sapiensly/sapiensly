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

#[Description('Aggregate records of an object: count, or sum/avg/min/max over a numeric field. Optionally broken down by a group_by field (with a date bucket for date/datetime fields) — "sum amount by month/by category" in one call — and optionally filtered.')]
class AggregateRecordsTool extends SapiensTool
{
    protected const ABILITY = 'data:read';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'object_id' => ['required', 'string'],
            'aggregation' => ['required', 'in:count,sum,avg,min,max'],
            'field_id' => ['nullable', 'string'],
            'filter' => ['sometimes', 'array'],
            'search' => ['sometimes', 'string'],
            'group_by' => ['sometimes', 'string'],
            'bucket' => ['sometimes', 'in:day,week,month,quarter,year'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ], [
            'aggregation.in' => 'aggregation must be one of: count, sum, avg, min, max.',
            'bucket.in' => 'bucket must be one of: day, week, month, quarter, year.',
        ]);

        if ($validated['aggregation'] !== 'count' && empty($validated['field_id'])) {
            return Response::error('field_id is required for sum/avg/min/max (the numeric field to aggregate).');
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
                );

                return Response::json([
                    'aggregation' => $validated['aggregation'],
                    'field_id' => $validated['field_id'] ?? null,
                    'group_by' => $validated['group_by'],
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
            'aggregation' => $schema->string()->enum(['count', 'sum', 'avg', 'min', 'max'])->description('The aggregation to compute.')->required(),
            'field_id' => $schema->string()->description('The numeric field id (required for sum/avg/min/max).'),
            'filter' => $schema->object()->description('Optional filter block (same shape as query_records, including {op: related, ...} relation traversal).'),
            'search' => $schema->string()->description('Optional free-text search across the object\'s text fields (case-insensitive; terms ANDed).'),
            'group_by' => $schema->string()->description('Optional field id to break the result down by. Returns {groups: [{group, value}]} instead of a single value.'),
            'bucket' => $schema->string()->enum(['day', 'week', 'month', 'quarter', 'year'])->description('When group_by is a date/datetime field, truncate it to this bucket.'),
            'limit' => $schema->integer()->description('Max groups to return when group_by is set (default 100).'),
        ];
    }
}
