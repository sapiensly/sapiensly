<?php

namespace App\Mcp\Tools\Platform;

use App\Mcp\Tools\SysadminTool;
use App\Models\Agent;
use App\Models\App;
use App\Models\Chatbot;
use App\Models\McpAccessToken;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('One account in full: identity and status, platform roles, EVERY organization it belongs to with the role and membership status in each, its MCP tokens, what it owns (apps, agents, chatbots), and whether it is the sole owner of any organization — which is what makes deleting it consequential. Accepts an email or a user id. Read-only; act with manage_platform_user or manage_organization_membership.')]
class InspectPlatformUserTool extends SysadminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'user' => ['required', 'string', 'max:320'],
        ]);

        $user = $this->resolveUser($validated['user']);

        if ($user === null) {
            return Response::error("No account matches '{$validated['user']}' (pass an email or a user id).");
        }

        $memberships = OrganizationMembership::query()
            ->where('user_id', $user->id)
            ->with('organization:id,name,slug')
            ->get();

        // An organization whose only owner is this account has no one else who
        // can administer it — the fact that turns a routine delete into an
        // orphaned tenant.
        $soleOwnerOf = [];
        $ownerships = $memberships->filter(fn (OrganizationMembership $m) => $m->role?->value === 'owner');

        foreach ($ownerships as $membership) {
            $otherOwners = OrganizationMembership::query()
                ->where('organization_id', $membership->organization_id)
                ->where('user_id', '!=', $user->id)
                ->where('role', 'owner')
                ->count();

            if ($otherOwners === 0) {
                $soleOwnerOf[] = [
                    'organization_id' => $membership->organization_id,
                    'name' => $membership->organization?->name,
                ];
            }
        }

        return Response::json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => match (true) {
                    $user->isBlocked() => 'blocked',
                    $user->email_verified_at === null => 'unverified',
                    default => 'active',
                },
                'blocked_at' => $user->blocked_at?->toIso8601String(),
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'two_factor' => $user->two_factor_confirmed_at !== null,
                'is_sysadmin' => $user->isSysAdmin(),
                'roles' => $user->roles->pluck('name')->values(),
                'locale' => $user->locale,
                'created_at' => $user->created_at?->toIso8601String(),
                'active_organization_id' => $user->organization_id,
            ],
            'organizations' => $memberships->map(fn (OrganizationMembership $membership) => [
                'organization_id' => $membership->organization_id,
                'name' => $membership->organization?->name,
                'slug' => $membership->organization?->slug,
                'role' => $membership->role?->value,
                'status' => $membership->status?->value,
                'is_active_organization' => $membership->organization_id === $user->organization_id,
                'joined_at' => $membership->created_at?->toIso8601String(),
            ])->values(),
            'sole_owner_of' => $soleOwnerOf,
            'owns' => [
                'apps' => App::query()->where('user_id', $user->id)->count(),
                'agents' => Agent::query()->where('user_id', $user->id)->count(),
                'chatbots' => Chatbot::query()->where('user_id', $user->id)->count(),
            ],
            'mcp_tokens' => McpAccessToken::query()
                ->where('user_id', $user->id)
                ->with('organization:id,name,slug')
                ->get()
                ->map(fn (McpAccessToken $token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'organization' => $token->organization?->slug,
                    'abilities' => $token->abilities ?: 'all tenant abilities',
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'expired' => $token->isExpired(),
                ])->values(),
        ]);
    }

    private function resolveUser(string $identifier): ?User
    {
        if (str_contains($identifier, '@')) {
            return User::whereRaw('lower(email) = ?', [strtolower($identifier)])->first();
        }

        return User::find($identifier);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'user' => $schema->string()->description('Email address or user id.')->required(),
        ];
    }
}
