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

    /**
     * Whether a token is present AND names this ability outright. Unlike
     * {@see self::allows()} this FAILS CLOSED when there is no token: the
     * absent-token case covers the OAuth (claude.ai) connection, which carries
     * no ability list at all, and in-process calls outside the HTTP middleware.
     * Platform administration must be deliberately granted on a minted personal
     * token, never inherited from "no restrictions recorded".
     */
    public function allowsExplicitly(string $ability): bool
    {
        return $this->token !== null
            && in_array($ability, $this->token->abilities ?? [], true);
    }
}
