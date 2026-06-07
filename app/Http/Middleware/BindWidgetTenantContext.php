<?php

namespace App\Http\Middleware;

use App\Models\Chatbot;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the tenant RLS scope for the widget API from the chatbot resolved
 * by ValidateWidgetApiToken. The widget runs without a session user, so the
 * `api` group never runs BindTenantContext; without this the tenant connection
 * has empty GUCs and every widget_* query is fail-closed under RLS. Scoping to
 * the chatbot's owner makes the per-query `chatbot_id` filter a redundant second
 * layer and keeps the connection safe to reuse (pooled / persistent).
 *
 * Must run AFTER ValidateWidgetApiToken (which attaches the chatbot). The scope
 * is set at the start of the request, not cleared in a `finally`: the SSE
 * `stream` endpoint writes its messages from a StreamedResponse callback that
 * runs after the middleware returns, so an early reset would break it. Cleanup
 * is handled the same way as BindTenantContext — connection close / pooler
 * reset / the next request overwriting the scope.
 */
class BindWidgetTenantContext
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $chatbot = $request->attributes->get('chatbot');

        if ($chatbot instanceof Chatbot) {
            $this->context->set($chatbot->organization_id, $chatbot->user_id);
        } else {
            // Defensive: ValidateWidgetApiToken guarantees a chatbot, but never
            // leave a previous request's scope in place — fail closed.
            $this->context->forget();
        }

        return $next($request);
    }
}
