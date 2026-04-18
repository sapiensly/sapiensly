<?php

namespace App\Http\Middleware;

use App\Services\CloudProviderService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pre-resolves the tenant's custom database connection once per request so that
 * downstream code (controllers, jobs dispatched synchronously, services) can
 * call CloudProviderService::tenantConnectionFor() without paying the lookup
 * cost repeatedly. When no tenant nor global database provider is configured,
 * this middleware is a no-op and the application default connection is used.
 */
class ResolveTenantConnection
{
    public function __construct(
        private CloudProviderService $cloudProviderService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $this->cloudProviderService->tenantConnectionFor($user);
        }

        return $next($request);
    }
}
