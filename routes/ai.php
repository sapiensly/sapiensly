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
| The per-tenant MCP endpoint accepts two credentials on the same URL:
|   - a personal McpAccessToken (Claude Code), and
|   - an OAuth 2.1 access token from the Passport flow below (Claude web).
| AuthenticateMcpToken resolves either, logs the user in (so throttle:mcp can
| key on them and tools can call $request->user()), then BindMcpTenantContext
| establishes the RLS scope.
|
| Mcp::oauthRoutes() publishes the OAuth discovery + dynamic client
| registration routes that claude.ai needs to connect.
|
*/

Mcp::oauthRoutes();

Mcp::web('mcp/v1', SapiensServer::class)
    ->middleware([
        AuthenticateMcpToken::class,
        'throttle:mcp',
        BindMcpTenantContext::class,
    ]);
