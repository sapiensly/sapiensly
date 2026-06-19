<?php

namespace App\Mcp\Tools\Build;

use App\Mcp\Tools\SapiensTool;
use App\Models\App;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('List the apps you can build/edit, with their slug, name and current version.')]
class ListAppsTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $apps = App::query()->forAccountContext($user)->get();

        return Response::json([
            'apps' => $apps->map(fn (App $app) => [
                'slug' => $app->slug,
                'name' => $app->name,
                'description' => $app->description,
                'has_active_version' => $app->current_version_id !== null,
            ])->values(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
