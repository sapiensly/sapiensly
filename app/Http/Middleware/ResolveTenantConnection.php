<?php

namespace App\Http\Middleware;

use App\Services\CloudProviderService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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
            try {
                $this->cloudProviderService->tenantConnectionFor($user);
            } catch (Throwable $e) {
                // Pre-resolution is a best-effort optimization. A misconfigured
                // or disallowed (SSRF-blocked) tenant DB host must NOT 500 every
                // request — log and fall through to the app default. The vector
                // store paths re-resolve and surface the failure gracefully.
                Log::warning('Tenant DB connection pre-resolution failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $next($request);
    }
}
