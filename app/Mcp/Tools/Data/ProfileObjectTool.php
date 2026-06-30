<?php

namespace App\Mcp\Tools\Data;

use App\Ai\Tools\Builder\ProfileObjectTool as BuilderProfileObjectTool;
use App\Mcp\Tools\SapiensTool;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\RecordQueryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Ai\Tools\Request as BuilderRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Profile the real data under an object to choose visualisations: classifies each field by analytic role (measure/temporal/categorical/identifier/relation) and reports cardinality, numeric min/max/avg/sum, date span and % nulls, then suggests grounded KPIs and charts. Use before building a dashboard so charts fit the data (e.g. top-N hbar instead of a pie on a high-cardinality field).')]
class ProfileObjectTool extends SapiensTool
{
    protected const ABILITY = 'data:read';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'object_id' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $app = $this->resolveApp($validated['app_slug'], $user);
        } catch (ModelNotFoundException) {
            return Response::error("No app named '{$validated['app_slug']}' is visible to you.");
        }

        $tool = new BuilderProfileObjectTool(
            $app,
            app(AppManifestService::class),
            app(RecordQueryService::class),
        );

        return Response::text($tool->handle(new BuilderRequest(['object_id' => $validated['object_id']])));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app_slug' => $schema->string()->description('The slug of the app.')->required(),
            'object_id' => $schema->string()->description('The object id to profile.')->required(),
        ];
    }
}
