<?php

use App\Http\Middleware\BindWidgetTenantContext;
use App\Models\Chatbot;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The widget runs without a session user, so this middleware is what scopes the
 * tenant connection (from the token-resolved chatbot's owner). Without it every
 * widget_* query would be fail-closed under RLS.
 */
function runBindWidgetTenantContext(TenantContext $context, ?Chatbot $chatbot): void
{
    $request = Request::create('/');
    if ($chatbot !== null) {
        $request->attributes->set('chatbot', $chatbot);
    }

    (new BindWidgetTenantContext($context))->handle($request, fn () => new Response);
}

it('scopes the tenant connection to the chatbot owner', function () {
    $chatbot = new Chatbot(['organization_id' => 'org_widget', 'user_id' => 7]);
    $context = Mockery::spy(TenantContext::class);

    runBindWidgetTenantContext($context, $chatbot);

    $context->shouldHaveReceived('set')->with('org_widget', 7);
    $context->shouldNotHaveReceived('forget');
});

it('fails closed when no chatbot was resolved', function () {
    $context = Mockery::spy(TenantContext::class);

    runBindWidgetTenantContext($context, null);

    $context->shouldHaveReceived('forget');
    $context->shouldNotHaveReceived('set');
});
