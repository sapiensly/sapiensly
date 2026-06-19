<?php

namespace App\Mcp\Tools\Build;

use App\Mcp\Tools\SapiensTool;
use App\Models\AppVersion;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description("Roll an app back to a previous version number. This is append-only: it creates a NEW version that copies the target's manifest, so history is preserved.")]
class RollbackAppTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'version_number' => ['required', 'integer', 'min:1'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $app = $this->resolveApp($validated['app_slug'], $user);
        } catch (ModelNotFoundException) {
            return Response::error("No app named '{$validated['app_slug']}' is visible to you.");
        }

        $target = AppVersion::query()
            ->where('app_id', $app->id)
            ->where('version_number', $validated['version_number'])
            ->first();

        if ($target === null) {
            return Response::error("App '{$app->slug}' has no version {$validated['version_number']}.");
        }

        try {
            $new = app(AppManifestService::class)->rollbackTo($app, $target, $user);
        } catch (\Throwable $e) {
            return Response::error('Rollback failed: '.$e->getMessage());
        }

        return Response::json([
            'rolled_back' => true,
            'restored_from_version' => $target->version_number,
            'new_version_number' => $new->version_number,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app_slug' => $schema->string()->description('The slug of the app.')->required(),
            'version_number' => $schema->integer()->description('The version number to restore.')->required(),
        ];
    }
}
