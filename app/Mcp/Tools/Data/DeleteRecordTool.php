<?php

namespace App\Mcp\Tools\Data;

use App\Mcp\Tools\SapiensTool;
use App\Models\Record;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\RecordWriteService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Delete a record by id. This is permanent — confirm with the user first.')]
class DeleteRecordTool extends SapiensTool
{
    protected const ABILITY = 'data:write';

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

        $manifest = app(AppManifestService::class)->getActiveManifest($app) ?? [];

        $record = Record::query()
            ->where('app_id', $app->id)
            ->where('object_definition_id', $validated['object_id'])
            ->find($validated['record_id']);

        if ($record === null) {
            return Response::error("No record '{$validated['record_id']}' found for that object.");
        }

        $id = $record->id;

        try {
            app(TenantContext::class)->runScoped(
                fn () => app(RecordWriteService::class)->delete($record, $app, $manifest, $user),
            );
        } catch (\Throwable $e) {
            return Response::error('Delete failed: '.$e->getMessage());
        }

        return Response::json(['deleted' => true, 'id' => $id]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app_slug' => $schema->string()->description('The slug of the app.')->required(),
            'object_id' => $schema->string()->description('The object id the record belongs to.')->required(),
            'record_id' => $schema->string()->description('The record id to delete.')->required(),
        ];
    }
}
