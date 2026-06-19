<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the tenant RLS scope for an MCP request from the user resolved by
 * AuthenticateMcpToken — the MCP endpoint has no session, so the `web` group's
 * BindTenantContext never runs. Scoping to the token user's organization (or the
 * user itself, in personal mode) mirrors BindTenantContext exactly.
 *
 * Pooling note: like every HTTP request, this sets the GUCs at session level
 * (see CLAUDE.md — assumes a dedicated/session-pooled tenant connection). Tools
 * that perform multi-statement writes under a transaction pooler should wrap
 * their DB work in TenantContext::runScoped(). Must run AFTER AuthenticateMcpToken.
 */
class BindMcpTenantContext
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->context->set($user->organization_id, $user->id);
        } else {
            // Defensive: AuthenticateMcpToken guarantees a user, but never leave
            // a previous request's scope in place — fail closed.
            $this->context->forget();
        }

        return $next($request);
    }
}
