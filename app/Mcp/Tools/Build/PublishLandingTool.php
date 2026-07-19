<?php

namespace App\Mcp\Tools\Build;

use App\Enums\AppKind;
use App\Mcp\Tools\SapiensTool;
use App\Models\App;
use App\Models\User;
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

        if ((bool) ($validated['unpublish'] ?? false)) {
            $app->forceFill(['public_slug' => null, 'published_at' => null])->save();

            return Response::json([
                'published' => false,
                'app_slug' => $app->slug,
                'message' => 'The landing is no longer publicly reachable.',
            ]);
        }

        if ($app->kind !== AppKind::Landing) {
            return Response::error(
                "Only landings can be published — '{$app->slug}' is a {$app->kind->value}. "
                .'Set settings.surface="landing" (and pass the design gate) first.',
            );
        }

        // Keep an already-published slug stable (republish = no-op on identity);
        // otherwise mint a globally-unique one from the app's own slug.
        $publicSlug = $app->public_slug ?? $this->mintPublicSlug($app);

        $app->forceFill([
            'public_slug' => $publicSlug,
            'published_at' => $app->published_at ?? now(),
        ])->save();

        return Response::json([
            'published' => true,
            'app_slug' => $app->slug,
            'public_slug' => $publicSlug,
            'url' => route('landing.public', ['public_slug' => $publicSlug]),
        ]);
    }

    /**
     * App slugs are only unique per-org; the public namespace is global. Use the
     * app's slug when free, else suffix a counter (yoga_studio, yoga_studio-2…).
     */
    private function mintPublicSlug(App $app): string
    {
        $base = $app->slug;
        $candidate = $base;
        $n = 2;
        while (App::query()->where('public_slug', $candidate)->exists()) {
            $candidate = "{$base}-{$n}";
            $n++;
        }

        return $candidate;
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
