<?php

use App\Http\Middleware\AuthenticateMcpToken;
use App\Http\Middleware\BindMcpTenantContext;
use App\Mcp\Servers\SapiensServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP Server
|--------------------------------------------------------------------------
|
| One MCP endpoint PER ORGANIZATION: the {organization} slug in the URL binds
| the connection to a specific org, independent of the user's mutable active
| org. It accepts two credentials:
|   - a personal McpAccessToken issued for that org (Claude Code), and
|   - an OAuth 2.1 access token from the Passport flow below (claude.ai).
| AuthenticateMcpToken resolves either, enforces org membership and pins the
| org; BindMcpTenantContext then establishes the RLS scope.
|
| Mcp::oauthRoutes() publishes the OAuth discovery + dynamic client
| registration routes that claude.ai needs to connect.
|
*/

Mcp::oauthRoutes();

Mcp::web('mcp/{organization}/v1', SapiensServer::class)
    ->middleware([
        AuthenticateMcpToken::class,
        'throttle:mcp',
        BindMcpTenantContext::class,
    ]);
