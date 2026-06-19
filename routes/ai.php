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
| The per-tenant MCP endpoint. Order matters: AuthenticateMcpToken logs in
| the token's user first (so throttle:mcp can key on it and tools can call
| $request->user()), then BindMcpTenantContext establishes the RLS scope.
|
*/

Mcp::web('mcp/v1', SapiensServer::class)
    ->middleware([
        AuthenticateMcpToken::class,
        'throttle:mcp',
        BindMcpTenantContext::class,
    ]);
