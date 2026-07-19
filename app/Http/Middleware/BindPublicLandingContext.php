<?php

namespace App\Http\Middleware;

use App\Enums\AppKind;
use App\Models\App;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves + gates the app behind a public landing URL and establishes the
 * tenant RLS scope for the request — the public sibling of
 * BindWidgetTenantContext. A guest has no session, so the tenant GUCs would be
 * empty and every tenant-schema query fail-closed; binding to the app OWNER's
 * scope is what lets authored content resolve (and, in the lead-capture slice,
 * what lets the lead insert satisfy RLS).
 *
 * The gate is strict and explicit: the app must have been PUBLISHED (a minted
 * public_slug + published_at) and be a landing. Everything else — including a
 * published app whose kind later stopped being a landing — is a plain 404, never
 * a hint that the slug exists. The `apps` lookup itself is safe without GUCs
 * (platform schema, structural isolation).
 */
class BindPublicLandingContext
{
    public function __construct(
        private readonly TenantContext $context,
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

        if ($app === null || $app->kind !== AppKind::Landing) {
            abort(404);
        }

        $this->context->set($app->organization_id, $app->user_id);
        $request->attributes->set('publicLandingApp', $app);

        return $next($request);
    }
}
