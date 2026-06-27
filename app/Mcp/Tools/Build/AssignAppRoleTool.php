<?php

namespace App\Mcp\Tools\Build;

use App\Mcp\Tools\SapiensTool;
use App\Models\User;
use App\Services\Apps\AppRoleAssignmentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Grant (or replace) an organization member\'s role on an app, identifying the member by email. The role_slug must be one of the app\'s manifest roles (call list_app_roles) and the member must be active in the app\'s organization. One role per member per app — assigning again replaces the prior role. This is runtime data, not a manifest change. Only an app/organization administrator may call this.')]
class AssignAppRoleTool extends SapiensTool
{
    use ManagesAppAccess;

    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'user_email' => ['required', 'email'],
            'role_slug' => ['required', 'string'],
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

        try {
            app(AppRoleAssignmentService::class)->assign(
                $resolved['app'],
                $resolved['manifest'],
                $user,
                $member->id,
                $validated['role_slug'],
            );
        } catch (ValidationException $e) {
            return Response::error(collect($e->errors())->flatten()->implode(' '));
        }

        return Response::json([
            'assigned' => true,
            'app_slug' => $resolved['app']->slug,
            'user_email' => $member->email,
            'role_slug' => $validated['role_slug'],
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app_slug' => $schema->string()->description('The slug of the app.')->required(),
            'user_email' => $schema->string()->description('Email of the organization member to assign. Must be an active member of the app\'s organization.')->required(),
            'role_slug' => $schema->string()->description('The role to grant — one of the app\'s manifest role slugs (see list_app_roles).')->required(),
        ];
    }
}
