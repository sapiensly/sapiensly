<?php

namespace App\Mcp\Tools\Data;

use App\Mcp\Tools\SapiensTool;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\AppDataOverview;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description("Describe an app's data model — the big picture before you query. Returns each object with its fields (id/slug/name/type, derived flag, relation targets), live record counts, the relation graph between objects, and which workflows fire on each object. Use this first, then query_records / aggregate_records for the detail. (read_manifest dumps the raw authored manifest; this is the digested data dictionary with live counts.)")]
class DescribeAppDataTool extends SapiensTool
{
    protected const ABILITY = 'data:read';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $app = $this->resolveApp($validated['app_slug'], $user);
        } catch (ModelNotFoundException) {
            return Response::error("No app named '{$validated['app_slug']}' is visible to you.");
        }

        $manifest = app(AppManifestService::class)->getActiveManifest($app);

        if ($manifest === null) {
            return Response::error("App '{$app->slug}' has no active version yet.");
        }

        return Response::json(app(AppDataOverview::class)->compact($app, $manifest));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app_slug' => $schema->string()
                ->description('The slug of the app whose data model to describe.')
                ->required(),
        ];
    }
}
