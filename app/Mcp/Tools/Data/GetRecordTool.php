<?php

namespace App\Mcp\Tools\Data;

use App\Mcp\Tools\SapiensTool;
use App\Models\Record;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Fetch a single record of an object by its id.')]
class GetRecordTool extends SapiensTool
{
    protected const ABILITY = 'data:read';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'object_id' => ['required', 'string'],
            'record_id' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $app = $this->resolveApp($validated['app_slug'], $user);
        } catch (ModelNotFoundException) {
            return Response::error("No app named '{$validated['app_slug']}' is visible to you.");
        }

        $record = Record::query()
            ->where('app_id', $app->id)
            ->where('object_definition_id', $validated['object_id'])
            ->find($validated['record_id']);

        if ($record === null) {
            return Response::error("No record '{$validated['record_id']}' found for that object.");
        }

        return Response::json([
            'id' => $record->id,
            'data' => $record->data,
            'created_at' => $record->created_at?->toIso8601String(),
            'updated_at' => $record->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app_slug' => $schema->string()->description('The slug of the app.')->required(),
            'object_id' => $schema->string()->description('The object id the record belongs to.')->required(),
            'record_id' => $schema->string()->description('The record id to fetch.')->required(),
        ];
    }
}
