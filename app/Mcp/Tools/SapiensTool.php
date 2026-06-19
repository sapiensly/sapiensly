<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Models\App;
use App\Models\User;
use Laravel\Mcp\Server\Tool;

/**
 * Base for every Sapiensly MCP tool. Two shared concerns:
 *   - ability gating: a tool declares the token ability it needs in ABILITY and
 *     is only registered (visible + callable) when the request's McpAccessToken
 *     grants it — so a read-only token never even sees the write tools;
 *   - tenant-scoped resolution: helpers resolve apps/the user through the same
 *     HasVisibility scopes the web controllers use, on top of the RLS scope that
 *     BindMcpTenantContext already set.
 */
abstract class SapiensTool extends Tool
{
    /** The token ability required to use this tool; null = no specific ability. */
    protected const ABILITY = null;

    /**
     * Registered only when the request's token grants this tool's ability.
     */
    public function shouldRegister(): bool
    {
        return app(McpContext::class)->allows(static::ABILITY);
    }

    /**
     * Resolve an app by slug within the caller's account context, or fail. The
     * forAccountContext scope is the same authorization the web controllers use.
     */
    protected function resolveApp(string $slug, User $user): App
    {
        return App::query()->forAccountContext($user)->where('slug', $slug)->firstOrFail();
    }
}
