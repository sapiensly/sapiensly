<?php

namespace App\Mcp\Tools\Build;

use App\Mcp\Tools\SapiensTool;
use App\Models\User;
use App\Services\Landing\LandingPublisher;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Publish a LANDING to its public, unauthenticated URL (or unpublish it). Publishing mints a globally-unique public slug and returns the live URL — until then a landing is only reachable by signed-in members. Only apps whose kind is landing (settings.surface="landing") can be published; the public page serves presentational sections only (data-backed blocks never render to anonymous visitors). Publishing is an outward-facing action: call it only when the user explicitly asks to publish.')]
class PublishLandingTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'unpublish' => ['sometimes', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $app = $this->resolveApp($validated['app_slug'], $user);
        } catch (ModelNotFoundException) {
            return Response::error("No app named '{$validated['app_slug']}' is visible to you.");
        }

        $publisher = app(LandingPublisher::class);

        if ((bool) ($validated['unpublish'] ?? false)) {
            $publisher->unpublish($app);

            return Response::json([
                'published' => false,
                'app_slug' => $app->slug,
                'message' => 'The landing is no longer publicly reachable.',
            ]);
        }

        try {
            $result = $publisher->publish($app);
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        return Response::json([
            'published' => true,
            'app_slug' => $app->slug,
            'public_slug' => $result['public_slug'],
            'url' => $result['url'],
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app_slug' => $schema->string()->description('The slug of the landing app to publish.')->required(),
            'unpublish' => $schema->boolean()->description('true to take the landing OFF the public internet (its public URL starts returning 404).'),
        ];
    }
}
