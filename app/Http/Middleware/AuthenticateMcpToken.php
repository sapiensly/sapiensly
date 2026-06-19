<?php

namespace App\Http\Middleware;

use App\Mcp\McpContext;
use App\Models\McpAccessToken;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates an MCP request by its Bearer token, accepting two credentials
 * on the same endpoint:
 *   1. a personal McpAccessToken (Claude Code) — resolved here, with the token's
 *      abilities exposed via McpContext;
 *   2. an OAuth 2.1 access token issued by Passport (the claude.ai connector) —
 *      validated by the `api` guard; OAuth is a translation layer to the user,
 *      so such a session is granted all abilities (a null McpContext).
 *
 * Either way the user is logged in for the request (so tools can call
 * $request->user() and policies resolve) before BindMcpTenantContext runs.
 */
class AuthenticateMcpToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->bearerToken();

        if (! $bearerToken) {
            return $this->unauthorized('Authorization header with a Bearer token is required.');
        }

        // 1) Personal MCP token (Claude Code).
        $token = McpAccessToken::where('token', $bearerToken)->first();
        if ($token && ! $token->isExpired() && $token->user) {
            $token->touchLastUsed();
            $this->login($request, $token->user, new McpContext($token));

            return $next($request);
        }

        // 2) OAuth 2.1 access token (Claude web). The passport guard parses the
        //    bearer; a personal token that didn't match above just fails to parse
        //    as a JWT and yields null here.
        $oauthUser = $request->user('api');
        if ($oauthUser instanceof User) {
            $this->login($request, $oauthUser, new McpContext(null));

            return $next($request);
        }

        return $this->unauthorized('The provided MCP credentials are invalid or have expired.');
    }

    private function login(Request $request, User $user, McpContext $context): void
    {
        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        app()->instance(McpContext::class, $context);
    }

    private function unauthorized(string $message): Response
    {
        return response()->json(['error' => 'Unauthorized', 'message' => $message], 401);
    }
}
