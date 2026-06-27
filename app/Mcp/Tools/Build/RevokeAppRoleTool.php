<?php

namespace App\Mcp\Tools\Build;

use App\Mcp\Tools\SapiensTool;
use App\Models\AppUserRole;
use App\Models\User;
use App\Services\Apps\AppRoleAssignmentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Remove a member\'s explicit app-role assignment, dropping them back to the app\'s default role (on an open app) or to no access (on an allowlist app). Identifies the member by email; a no-op if they had no explicit role. Only an app/organization administrator may call this.')]
class RevokeAppRoleTool extends SapiensTool
{
    use ManagesAppAccess;

    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'user_email' => ['required', 'email'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $resolved = $this->loadManageableApp($user, $validated['app_slug']);
        if (! $resolved['ok']) {
            return Response::error($resolved['error']);
        }

        $member = User::query()->where('email', $validated['user_email'])->first();
        if ($member === null) {
            return Response::error("No user with email '{$validated['user_email']}' exists.");
        }

        $assignment = AppUserRole::query()
            ->where('app_id', $resolved['app']->id)
            ->where('assigned_user_id', $member->id)
            ->first();

        $revoked = $assignment !== null
            && app(AppRoleAssignmentService::class)->revoke($resolved['app'], $assignment->id);

        return Response::json([
            'revoked' => $revoked,
            'app_slug' => $resolved['app']->slug,
            'user_email' => $member->email,
            'note' => $revoked
                ? 'Member dropped to the default role (open) or denied (allowlist).'
                : 'Member had no explicit role; nothing to revoke.',
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app_slug' => $schema->string()->description('The slug of the app.')->required(),
            'user_email' => $schema->string()->description('Email of the member whose explicit role to remove.')->required(),
        ];
    }
}
