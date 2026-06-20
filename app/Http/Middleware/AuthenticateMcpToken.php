<?php

namespace App\Http\Middleware;

use App\Mcp\McpContext;
use App\Models\McpAccessToken;
use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates an organization-bound MCP request. The organization comes from
 * the URL (`mcp/{organization}/v1`), NOT the user's mutable active org, so a
 * credential is pinned to one org. Two credentials are accepted on the same URL:
 *   1. a personal McpAccessToken (Claude Code) — must belong to this org;
 *   2. an OAuth 2.1 access token issued by Passport (claude.ai) — any user.
 *
 * Either way the principal must be an active member of the org. The user's
 * active-org pointer is then overridden IN MEMORY to the URL org, so
 * HasVisibility::forAccountContext and the RLS scope set by BindMcpTenantContext
 * agree on the pinned org regardless of which org the user has active elsewhere.
 */
class AuthenticateMcpToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $org = Organization::where('slug', $request->route('organization'))->first();
        if (! $org) {
            return $this->unauthorized('Unknown organization in the MCP URL.');
        }

        $bearerToken = $request->bearerToken();
        if (! $bearerToken) {
            return $this->unauthorized('Authorization header with a Bearer token is required.');
        }

        [$user, $context] = $this->resolveCredential($request, $bearerToken, $org);
        if (! $user) {
            return $this->unauthorized('The provided MCP credentials are invalid or have expired.');
        }

        if (! $user->isActiveMemberOf($org->id)) {
            return $this->forbidden('You are not a member of this organization.');
        }

        // Pin the credential's org in memory so forAccountContext + RLS agree,
        // independent of the user's active-org pointer.
        $user->organization_id = $org->id;
        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        app()->instance(McpContext::class, $context);

        return $next($request);
    }

    /**
     * @return array{0: ?User, 1: ?McpContext}
     */
    private function resolveCredential(Request $request, string $bearerToken, Organization $org): array
    {
        // 1) Personal MCP token (Claude Code) — must be issued for this org.
        $token = McpAccessToken::where('token', $bearerToken)->first();
        if ($token && ! $token->isExpired() && $token->user) {
            if ($token->organization_id !== $org->id) {
                return [null, null];
            }
            $token->touchLastUsed();

            return [$token->user, new McpContext($token)];
        }

        // 2) OAuth 2.1 access token (claude.ai). The personal-token miss above
        //    falls through; the passport guard parses the bearer as a JWT.
        $oauthUser = $request->user('api');
        if ($oauthUser instanceof User) {
            return [$oauthUser, new McpContext(null)];
        }

        return [null, null];
    }

    private function unauthorized(string $message): Response
    {
        return response()->json(['error' => 'Unauthorized', 'message' => $message], 401);
    }

    private function forbidden(string $message): Response
    {
        return response()->json(['error' => 'Forbidden', 'message' => $message], 403);
    }
}
