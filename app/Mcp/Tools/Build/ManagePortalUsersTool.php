<?php

namespace App\Mcp\Tools\Build;

use App\Mcp\Tools\SapiensTool;
use App\Models\User;
use App\Services\Apps\PortalUserDirectory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Manage who may sign in to an app\'s public PORTAL. Actions: list, invite (add an address that may sign in), block (refuse someone without losing the records their id scopes), unblock, remove (delete the identity — prefer block, since anything scoped by their id becomes unreachable). Required for a portal whose permissions.public.signup is "invite": that mode only mails addresses added here, so without an invite nobody can get in. A portal user belongs to this app alone — no organization membership, no platform role, no access anywhere else.')]
class ManagePortalUsersTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'action' => ['required', 'string', 'in:list,invite,block,unblock,remove'],
            'email' => ['sometimes', 'nullable', 'string', 'max:320'],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $app = $this->resolveApp($validated['app_slug'], $user);
        } catch (ModelNotFoundException) {
            return Response::error("No app named '{$validated['app_slug']}' is visible to you.");
        }

        $directory = app(PortalUserDirectory::class);
        $action = $validated['action'];

        if ($action === 'list') {
            return Response::json([
                'app_slug' => $app->slug,
                'portal_users' => $directory->list($app),
            ]);
        }

        $email = trim((string) ($validated['email'] ?? ''));
        if ($email === '') {
            return Response::error("`email` is required to {$action} a portal user.");
        }

        try {
            $result = match ($action) {
                'invite' => $directory->invite($app, $email, $validated['name'] ?? null),
                'block' => $directory->block($app, $email),
                'unblock' => $directory->unblock($app, $email),
                'remove' => $directory->remove($app, $email),
            };
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        if ($result === null || $result === false) {
            return Response::error("No portal user with that address exists on '{$app->slug}'.");
        }

        return Response::json([
            'app_slug' => $app->slug,
            'action' => $action,
            'email' => $email,
            'portal_users' => $directory->list($app),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app_slug' => $schema->string()->description('The app whose portal to manage.')->required(),
            'action' => $schema->string()->description('list | invite | block | unblock | remove')->required(),
            'email' => $schema->string()->description('The person\'s address. Required for everything but list.'),
            'name' => $schema->string()->description('Optional display name, used when inviting.'),
        ];
    }
}
