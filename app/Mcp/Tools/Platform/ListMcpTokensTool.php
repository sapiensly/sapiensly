<?php

namespace App\Mcp\Tools\Platform;

use App\Mcp\Tools\SysadminTool;
use App\Models\McpAccessToken;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Every MCP access token issued on this platform, across all organizations: who owns it, which organization it is pinned to, which abilities it was granted, when it was last used and whether it has expired or is simply dormant. Tokens are shown as a short prefix only — the value exists in full nowhere after it is created. Filter by organization, by owner, or to only those never used or unused for N days. Read-only; revoke with revoke_mcp_token. Issuing tokens is deliberately NOT possible here: a tool that mints credentials is a privilege-escalation path, so new tokens are created by a person in the app.')]
class ListMcpTokensTool extends SysadminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'organization' => ['sometimes', 'nullable', 'string', 'max:120'],
            'user' => ['sometimes', 'nullable', 'string', 'max:320'],
            'unused_for_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
            'never_used' => ['sometimes', 'boolean'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $query = McpAccessToken::query()->with(['user:id,name,email', 'organization:id,name,slug']);

        if (! empty($validated['organization'])) {
            $organization = $validated['organization'];
            $query->where(function ($inner) use ($organization) {
                $inner->where('organization_id', $organization)
                    ->orWhereHas('organization', fn ($o) => $o->where('slug', $organization));
            });
        }

        if (! empty($validated['user'])) {
            $needle = strtolower($validated['user']);
            $query->whereHas('user', fn ($u) => $u->whereRaw('lower(email) = ?', [$needle]));
        }

        if (! empty($validated['never_used'])) {
            $query->whereNull('last_used_at');
        }

        if (! empty($validated['unused_for_days'])) {
            $cutoff = now()->subDays((int) $validated['unused_for_days']);
            $query->where(function ($inner) use ($cutoff) {
                $inner->whereNull('last_used_at')->orWhere('last_used_at', '<', $cutoff);
            });
        }

        $total = (clone $query)->count();
        $tokens = $query->latest()->limit((int) ($validated['limit'] ?? 50))->get();

        return Response::json([
            'total_matching' => $total,
            'returned' => $tokens->count(),
            'tokens' => $tokens->map(fn (McpAccessToken $token) => [
                'id' => $token->id,
                'name' => $token->name,
                'prefix' => substr($token->token, 0, 8).'…',
                'owner' => [
                    'user_id' => $token->user_id,
                    'name' => $token->user?->name,
                    'email' => $token->user?->email,
                ],
                'organization' => $token->organization === null ? null : [
                    'id' => $token->organization->id,
                    'name' => $token->organization->name,
                    'slug' => $token->organization->slug,
                ],
                'abilities' => $token->abilities ?: ['(all tenant abilities)'],
                'platform_admin' => in_array(McpAccessToken::PLATFORM_ADMIN, $token->abilities ?? [], true),
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'expires_at' => $token->expires_at?->toIso8601String(),
                'expired' => $token->isExpired(),
                'created_at' => $token->created_at?->toIso8601String(),
            ])->values(),
            'note' => 'An empty ability list grants every TENANT ability but never platform:admin — that one is only ever granted by being named explicitly.',
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Only tokens pinned to this organization (id or slug).'),
            'user' => $schema->string()->description('Only tokens owned by this email address.'),
            'unused_for_days' => $schema->integer()->description('Only tokens not used in this many days (includes never-used).'),
            'never_used' => $schema->boolean()->description('Only tokens that have never authenticated a call.'),
            'limit' => $schema->integer()->description('How many to return, 1-200. Default 50.'),
        ];
    }
}
