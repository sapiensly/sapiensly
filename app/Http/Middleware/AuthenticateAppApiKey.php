<?php

namespace App\Http\Middleware;

use App\Services\Apps\ApiKeyService;
use App\Services\Apps\AppAccessResolver;
use App\Services\Manifest\AppManifestService;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a request to an app's REST data API and puts it inside the
 * right tenant.
 *
 * The order is forced by the problem: a request arrives carrying nothing but a
 * bearer token, so the key is resolved FIRST (from the platform schema, where
 * isolation is structural and no tenant scope is needed), and only then is the
 * owner's tenant scope bound. Binding a scope before knowing whose it is would
 * be the bug this ordering exists to prevent.
 *
 * The key never carries permissions of its own. It names an app ROLE, and the
 * same AppAccessResolver the UI uses turns that role into the capabilities every
 * downstream read and write is gated by. The key's `scopes` narrow that further,
 * checked in the controller.
 *
 * Every failure is 401 with the same body. Distinguishing "no such key" from
 * "revoked" from "wrong app" would confirm which tokens exist.
 */
class AuthenticateAppApiKey
{
    public function __construct(
        private readonly ApiKeyService $keys,
        private readonly AppManifestService $manifests,
        private readonly AppAccessResolver $accessResolver,
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Never inherit a previous request's scope on a pooled worker.
        $this->context->forget();

        $key = $this->keys->resolve((string) $request->bearerToken());
        if ($key === null) {
            return $this->unauthorized();
        }

        $app = $key->app;
        if ($app === null) {
            return $this->unauthorized();
        }

        $this->context->set($app->organization_id, $app->user_id);

        $manifest = $this->manifests->getActiveManifest($app);
        if ($manifest === null) {
            $this->context->forget();

            return $this->unauthorized();
        }

        $access = $this->accessResolver->resolveForRole($manifest, $key->role_slug);
        if (! $access->hasAccess) {
            $this->context->forget();

            return $this->unauthorized();
        }

        // Best-effort usage stamp: it must never fail a request, and a write on
        // every call is not worth a transaction.
        $key->forceFill(['last_used_at' => now()])->saveQuietly();

        $request->attributes->set('apiKey', $key);
        $request->attributes->set('apiApp', $app);
        $request->attributes->set('apiManifest', $manifest);
        $request->attributes->set('apiAccess', $access);

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response()->json([
            'error' => 'unauthorized',
            'message' => 'Provide a valid API key as `Authorization: Bearer <token>`.',
        ], 401);
    }
}
