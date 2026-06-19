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

#[Description('Aggregate records of an object: count, or sum/avg/min/max over a numeric field. Optionally filtered.')]
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
        ], [
            'aggregation.in' => 'aggregation must be one of: count, sum, avg, min, max.',
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
        if (isset($validated['filter'])) {
            $query['filter'] = $validated['filter'];
        }

        try {
            $value = app(RecordQueryService::class)->aggregate(
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
            'filter' => $schema->object()->description('Optional manifest-style filter block.'),
        ];
    }
}
