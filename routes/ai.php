<?php

use App\Http\Middleware\AuthenticateMcpToken;
use App\Http\Middleware\AuthenticatePlatformMcpToken;
use App\Http\Middleware\BindMcpTenantContext;
use App\Mcp\Servers\SapiensServer;
use App\Mcp\Servers\SysadminServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP Servers
|--------------------------------------------------------------------------
|
| TWO endpoints, because there are two genuinely different scopes.
|
| Per organization — `mcp/{organization}/v1`. The slug binds the connection to
| one org, independent of the user's mutable active org. It accepts two
| credentials: a personal McpAccessToken issued for that org (Claude Code), and
| an OAuth 2.1 access token from the Passport flow (claude.ai).
| AuthenticateMcpToken resolves either, enforces org membership and pins the
| org; BindMcpTenantContext then establishes the RLS scope.
|
| Platform-wide — `mcp/platform/v1`. Administering the platform is not something
| that happens inside a tenant, so this one carries no organization at all: no
| slug in the URL, no membership to check, no tenant scope established. Its
| credential must name `platform:admin` and belong to a live sysadmin.
|
| `platform` cannot collide with a real organization: the platform route is
| registered FIRST so it wins the match, and `platform` is a reserved slug
| ({@see \App\Models\Organization::RESERVED_SLUGS}) refused at creation time —
| otherwise an org that took the name would silently become unreachable here.
|
| Mcp::oauthRoutes() publishes the OAuth discovery + dynamic client
| registration routes that claude.ai needs to connect.
|
*/

Mcp::oauthRoutes();

Mcp::web('mcp/platform/v1', SysadminServer::class)
    ->middleware([
        AuthenticatePlatformMcpToken::class,
        'throttle:mcp',
    ]);

Mcp::web('mcp/{organization}/v1', SapiensServer::class)
    ->middleware([
        AuthenticateMcpToken::class,
        'throttle:mcp',
        BindMcpTenantContext::class,
    ]);
