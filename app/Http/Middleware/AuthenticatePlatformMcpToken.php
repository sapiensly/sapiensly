<?php

namespace App\Http\Middleware;

use App\Mcp\McpContext;
use App\Models\McpAccessToken;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the PLATFORM MCP endpoint (`mcp/platform/v1`), which has no
 * organization in its URL because nothing it serves belongs to one.
 *
 * Three conditions, all required:
 *   1. a personal McpAccessToken that is not expired;
 *   2. that names `platform:admin` outright — an empty ability list means
 *      "every tenant ability" and deliberately never reaches this one;
 *   3. whose owner still holds the platform sysadmin role, checked live, so a
 *      token outlives its holder's authority by exactly zero requests.
 *
 * Unlike the per-organization endpoint there is no membership check, because
 * there is no organization to be a member of. And unlike it, no tenant scope is
 * established: this connection carries the platform suite, which reads through
 * the owner connection on purpose. Leaving the scope UNSET means any query that
 * accidentally went through the tenant connection fails closed instead of
 * quietly running against whatever scope the previous request left behind.
 */
class AuthenticatePlatformMcpToken
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->bearerToken();

        if (! $bearerToken) {
            return $this->unauthorized('Authorization header with a Bearer token is required.');
        }

        $token = McpAccessToken::where('token', $bearerToken)->first();

        if (! $token || $token->isExpired() || ! $token->user) {
            return $this->unauthorized('The provided MCP credentials are invalid or have expired.');
        }

        if (! $token->hasAbility(McpAccessToken::PLATFORM_ADMIN)) {
            return $this->forbidden(
                'This endpoint serves platform administration only. A token for one organization connects at mcp/{organization}/v1 instead.'
            );
        }

        /** @var User $user */
        $user = $token->user;

        if (! $user->isSysAdmin()) {
            return $this->forbidden('This token\'s owner is not a platform sysadmin.');
        }

        $token->touchLastUsed();

        // No organization: the platform tools act across all of them, and
        // pretending otherwise would put a scope in whoami that nothing honours.
        $user->organization_id = null;
        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        app()->instance(McpContext::class, new McpContext($token));

        // The sysadmin role is global (assigned with a null spatie team), so the
        // permission scope matches: no organization is active on this connection.
        setPermissionsTeamId(null);

        // Fail closed — see the class docblock.
        $this->tenant->forget();

        return $next($request);
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
