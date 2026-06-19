<?php

namespace App\Http\Middleware;

use App\Mcp\McpContext;
use App\Models\McpAccessToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates an MCP request by its Bearer token (laravel/mcp "custom
 * authentication"). Mirrors ValidateWidgetApiToken, but resolves to a USER and
 * logs them in for the request so MCP tools can use `$request->user()` and the
 * existing policies. The resolved token is bound into the container as an
 * McpContext so tools can gate on its abilities. Must run before
 * BindMcpTenantContext.
 */
class AuthenticateMcpToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->bearerToken();

        if (! $bearerToken) {
            return $this->unauthorized('Authorization header with a Bearer token is required.');
        }

        $token = McpAccessToken::where('token', $bearerToken)->first();

        if (! $token || $token->isExpired()) {
            return $this->unauthorized('The provided MCP token is invalid or has expired.');
        }

        $user = $token->user;

        if (! $user) {
            return $this->unauthorized('The user associated with this token no longer exists.');
        }

        $token->touchLastUsed();

        // Authenticate the user for this request so tools can call
        // $request->user() and Gate/policy checks resolve correctly.
        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        // Expose the token's abilities to the tools for this request.
        app()->instance(McpContext::class, new McpContext($token));

        return $next($request);
    }

    private function unauthorized(string $message): Response
    {
        return response()->json(['error' => 'Unauthorized', 'message' => $message], 401);
    }
}
