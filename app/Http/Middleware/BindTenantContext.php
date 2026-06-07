<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the authenticated user's account context to the tenant connection so
 * RLS scopes every `tenant`-schema query for the rest of the request. Mirrors
 * SetPermissionsTeam (which sets the Spatie team from the same
 * `organization_id`) and runs right after it.
 *
 * Unauthenticated requests leave the context empty, so the tenant connection is
 * fail-closed (zero rows) — public widget endpoints that legitimately read
 * tenant data must establish context explicitly from the resolved chatbot/owner.
 *
 * Persistence note: a NON-persistent tenant connection is born clean each
 * request, so leaving the context untouched on a guest request is already
 * fail-closed. A PERSISTENT connection survives across requests on a worker, so
 * a guest request would otherwise inherit the previous (authenticated)
 * request's tenant scope. When persistence is on we reset the scope explicitly;
 * otherwise the reset is skipped so non-tenant guest requests never pay an
 * extra connection round-trip.
 */
class BindTenantContext
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            $this->context->set($user->organization_id, $user->id);
        } elseif ((bool) config('database.tenant_persistent', false)) {
            $this->context->forget();
        }

        return $next($request);
    }
}
