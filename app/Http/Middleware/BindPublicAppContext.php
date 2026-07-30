<?php

namespace App\Http\Middleware;

use App\Enums\AppKind;
use App\Models\App;
use App\Services\Manifest\AppManifestService;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves + gates the app behind a public PORTAL URL and establishes the
 * tenant RLS scope for the request — the portal sibling of
 * {@see BindPublicLandingContext}. A visitor has no session, so the tenant GUCs
 * would be empty and every tenant-schema query would fail closed; binding to
 * the app OWNER's scope is what lets the portal's granted data resolve at all.
 *
 * The gate is strict and layered, and every failure is an identical 404 — never
 * a 403, which would confirm that the slug exists:
 *   1. the slug must belong to a PUBLISHED app,
 *   2. that is not a landing (landings have their own surface at /l/{slug}),
 *   3. whose ACTIVE manifest still opens the portal.
 *
 * (3) is the one that matters over time: unpublishing is not the only way a
 * portal closes. An author who removes permissions.public from the manifest has
 * closed it too, and the URL must stop serving that same second — not whenever
 * someone remembers to also unpublish.
 */
class BindPublicAppContext
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AppManifestService $manifestService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Never inherit a previous request's scope (pooled workers) — resolve
        // from scratch, fail closed.
        $this->context->forget();

        $slug = (string) $request->route('public_slug');
        $app = App::query()
            ->where('public_slug', $slug)
            ->whereNotNull('published_at')
            ->first();

        if ($app === null || $app->kind === AppKind::Landing) {
            abort(404);
        }

        // The manifest read needs the scope, and the scope is safe to set from
        // the app row itself (the `apps` lookup above is platform-schema, where
        // isolation is structural).
        $this->context->set($app->organization_id, $app->user_id);

        $manifest = $this->manifestService->getActiveManifest($app);
        if ($manifest === null || ($manifest['permissions']['public']['enabled'] ?? false) !== true) {
            $this->context->forget();
            abort(404);
        }

        $request->attributes->set('publicApp', $app);
        $request->attributes->set('publicAppManifest', $manifest);

        return $next($request);
    }
}
