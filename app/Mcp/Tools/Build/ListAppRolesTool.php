<?php

namespace App\Mcp\Tools\Build;

use App\Mcp\Tools\SapiensTool;
use App\Models\User;
use App\Services\Apps\AppRoleAssignmentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('List who can use an app and in which role: the app\'s access_mode (open|allowlist), its manifest roles, and every active organization member with their current app role (null = the default role). Read framework_reference topic=permissions to understand the model. Only an app/organization administrator may call this.')]
class ListAppRolesTool extends SapiensTool
{
    use ManagesAppAccess;

    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $resolved = $this->loadManageableApp($user, $validated['app_slug']);
        if (! $resolved['ok']) {
            return Response::error($resolved['error']);
        }

        return Response::json([
            'app_slug' => $resolved['app']->slug,
        ] + app(AppRoleAssignmentService::class)->roster($resolved['app'], $resolved['manifest']));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app_slug' => $schema->string()->description('The slug of the app whose access roster to read.')->required(),
        ];
    }
}
