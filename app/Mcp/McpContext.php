<?php

namespace App\Mcp;

use App\Models\McpAccessToken;

/**
 * Per-request holder for the access token that authenticated the MCP call, so
 * tools can gate themselves on the token's abilities (see SapiensTool). Bound
 * into the container by AuthenticateMcpToken. When no token is bound — i.e.
 * outside the HTTP middleware, as in unit tests or CLI — ability checks pass,
 * since in production the route middleware guarantees a token.
 */
class McpContext
{
    public function __construct(public readonly ?McpAccessToken $token = null) {}

    public function allows(?string $ability): bool
    {
        if ($ability === null || $this->token === null) {
            return true;
        }

        return $this->token->hasAbility($ability);
    }
}
