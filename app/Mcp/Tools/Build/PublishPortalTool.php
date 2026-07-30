<?php

namespace App\Mcp\Tools\Build;

use App\Mcp\Tools\SapiensTool;
use App\Models\User;
use App\Services\Apps\PortalPublisher;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Publish a regular APP as a PUBLIC PORTAL — reachable at /a/{public_slug} by anyone, with no account (or unpublish it). The app must first declare permissions.public = {enabled: true, role_id, allow_writes} and grant that visitor role the pages and objects strangers may reach: a portal is DENY-BY-DEFAULT, so a page or object with no explicit policy is invisible to visitors. Landings are not portals — publish those with publish_landing. Publishing puts tenant data on the internet: call it only when the user explicitly asks to.')]
class PublishPortalTool extends SapiensTool
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

        $publisher = app(PortalPublisher::class);

        if ((bool) ($validated['unpublish'] ?? false)) {
            $publisher->unpublish($app);

            return Response::json([
                'published' => false,
                'app_slug' => $app->slug,
                'message' => 'The portal is no longer publicly reachable.',
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
            'visitor_role' => $result['role'],
            'visitors_can_write' => $result['writes'],
            'note' => $result['writes']
                ? 'Visitors may submit data on the objects their role grants create/update/delete.'
                : 'The portal is read-only: visitors can browse what their role may read, and submit nothing.',
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app_slug' => $schema->string()->description('The slug of the app to open as a public portal.')->required(),
            'unpublish' => $schema->boolean()->description('true to take the portal OFF the public internet (its public URL starts returning 404).'),
        ];
    }
}
