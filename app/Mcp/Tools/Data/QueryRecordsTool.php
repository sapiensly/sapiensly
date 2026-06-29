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

#[Description('Query records of an object in an app. Returns matching records plus the total count and a has_more flag for paging (tenant-scoped). Pass `expand` to resolve belongs_to relations inline. Call describe_app_data first to learn the object and field ids.')]
class QueryRecordsTool extends SapiensTool
{
    protected const ABILITY = 'data:read';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'object_id' => ['required', 'string'],
            'filter' => ['sometimes', 'array'],
            'search' => ['sometimes', 'string'],
            'sort' => ['sometimes', 'array'],
            'expand' => ['sometimes', 'array'],
            'expand.*' => ['string'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'offset' => ['sometimes', 'integer', 'min:0'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $app = $this->resolveApp($validated['app_slug'], $user);
        } catch (ModelNotFoundException) {
            return Response::error("No app named '{$validated['app_slug']}' is visible to you.");
        }

        $manifest = app(AppManifestService::class)->getActiveManifest($app);

        $query = ['object_id' => $validated['object_id'], 'limit' => $validated['limit'] ?? 50];
        foreach (['filter', 'search', 'sort', 'offset', 'expand'] as $key) {
            if (isset($validated[$key])) {
                $query[$key] = $validated[$key];
            }
        }

        try {
            $result = app(RecordQueryService::class)->queryWithMeta($app, $query, $manifest ?? []);
        } catch (\Throwable $e) {
            return Response::error('Query failed: '.$e->getMessage());
        }

        return Response::json([
            'count' => $result['records']->count(),
            'total' => $result['total'],
            'has_more' => $result['has_more'],
            'records' => $result['records']->map(function ($r) {
                $row = [
                    'id' => $r->id,
                    'data' => $r->data,
                    'created_at' => $r->created_at?->toIso8601String(),
                ];
                // Inline belongs_to relations resolved via `expand`.
                if ($r->expanded !== []) {
                    $row['expanded'] = $r->expanded;
                }

                return $row;
            })->values(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app_slug' => $schema->string()->description('The slug of the app.')->required(),
            'object_id' => $schema->string()->description('The object id to query records of.')->required(),
            'filter' => $schema->object()->description('Optional filter block: {op, ...}. Leaf ops: eq/neq/gt/gte/lt/lte/in/not_in/contains/starts_with/ends_with/between/is_null/is_not_null referencing field_ids. Logical: and/or/not. Relation traversal: {op: related, field_id: <relation field>, condition: <filter on the related object>} (belongs_to and has_many; nestable).'),
            'search' => $schema->string()->description('Optional free-text search across the object\'s text fields (case-insensitive; terms ANDed).'),
            'sort' => $schema->array()->description('Optional [{field_id, direction: asc|desc}].'),
            'expand' => $schema->array()->description('Optional list of belongs_to relation field ids to resolve inline. Each returned record gains expanded: { [field_id]: { id, data } | null } with the related record (access-respecting; null if empty/not visible).'),
            'limit' => $schema->integer()->description('Max records to return (default 50, max 200).'),
            'offset' => $schema->integer()->description('Rows to skip, for paging (default 0).'),
        ];
    }
}
