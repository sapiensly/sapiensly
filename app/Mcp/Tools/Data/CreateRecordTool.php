<?php

namespace App\Mcp\Tools\Data;

use App\Mcp\Tools\SapiensTool;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\RecordValidationException;
use App\Services\Records\RecordWriteService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Create a record for an object in an app. The `values` map is keyed by field slug or field id (both work); values are validated against the object\'s fields. Use read_manifest first to learn the object\'s fields.')]
class CreateRecordTool extends SapiensTool
{
    protected const ABILITY = 'data:write';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'object_id' => ['required', 'string'],
            'values' => ['required', 'array'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $app = $this->resolveApp($validated['app_slug'], $user);
        } catch (ModelNotFoundException) {
            return Response::error("No app named '{$validated['app_slug']}' is visible to you.");
        }

        $manifest = app(AppManifestService::class)->getActiveManifest($app);

        try {
            // Wrap the write so the tenant GUCs are applied transaction-locally,
            // which is required when the tenant connection goes through a
            // transaction pooler (the middleware's session-level set is lost).
            $record = app(TenantContext::class)->runScoped(
                fn () => app(RecordWriteService::class)->create(
                    $app,
                    $manifest ?? [],
                    $validated['object_id'],
                    $validated['values'],
                    $user,
                ),
            );
        } catch (RecordValidationException $e) {
            return Response::error('Validation failed: '.json_encode($e->errors));
        } catch (\Throwable $e) {
            return Response::error('Create failed: '.$e->getMessage());
        }

        return Response::json([
            'created' => true,
            'id' => $record->id,
            'data' => $record->data,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app_slug' => $schema->string()->description('The slug of the app.')->required(),
            'object_id' => $schema->string()->description('The object id to create a record for.')->required(),
            'values' => $schema->object()->description('Map of field (slug or id) => value for the new record.')->required(),
        ];
    }
}
